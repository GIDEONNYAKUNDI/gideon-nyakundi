<?php
/* Nyakundi 2027 back office: polling station agents, volunteers and pledges. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

security_headers();

if (!cfg()) {
    page_head('Set up needed');
    echo '<main class="narrow"><h1>Back office not set up yet</h1><p>In cPanel File Manager, open <b>public_html/backoffice</b>, copy <b>config.sample.php</b> to <b>config.php</b> and enter your MySQL database name, user and password. Then reload this page.</p></main>';
    page_foot(); exit;
}
try { install_schema(); } catch (Throwable $e) {
    error_log('backoffice: ' . $e->getMessage());
    page_head('Database error');
    echo '<main class="narrow"><h1>Cannot reach the database</h1><p>Check the database name, user and password in <b>backoffice/config.php</b>, and that the user has been added to the database with all privileges in cPanel &gt; MySQL Databases.</p></main>';
    page_foot(); exit;
}
start_session();

$p = $_GET['p'] ?? 'home';
$userCount = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

/* ---------- first run: create the first administrator ---------- */
if ($userCount === 0) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
        $u = trim((string)($_POST['username'] ?? '')); $pw = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $u)) $err = 'Username: 3 to 60 letters, numbers, dot, dash or underscore.';
        elseif (strlen($pw) < 10) $err = 'Password must be at least 10 characters.';
        elseif ($pw !== ($_POST['password2'] ?? '')) $err = 'The two passwords do not match.';
        else {
            db()->prepare('INSERT INTO users (username, pass_hash, role, constituency, created_at) VALUES (?,?,?,?,?)')->execute([$u, password_hash($pw, PASSWORD_DEFAULT), 'admin', '', now()]);
            session_regenerate_id(true); $_SESSION['uid'] = (int)db()->lastInsertId();
            header('Location: ./'); exit;
        }
    }
    page_head('Create the first administrator');
    echo '<main class="narrow"><h1>Create the first administrator</h1><p class="muted">This page only appears once, before any account exists.</p>';
    if ($err) echo '<p class="err">' . h($err) . '</p>';
    echo '<form method="post" class="stack"><input type="hidden" name="csrf" value="' . h(csrf()) . '">
      <label>Username<input name="username" required autocomplete="username"></label>
      <label>Password (at least 10 characters)<input name="password" type="password" required autocomplete="new-password"></label>
      <label>Repeat password<input name="password2" type="password" required autocomplete="new-password"></label>
      <button class="btn">Create administrator</button></form></main>';
    page_foot(); exit;
}

/* ---------- login / logout ---------- */
if ($p === 'logout') { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
$me = current_user();
if (!$me) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
        if (too_many('login', 8, 900)) { $err = 'Too many attempts. Wait 15 minutes and try again.'; }
        else {
            $s = db()->prepare('SELECT id, pass_hash FROM users WHERE username = ?');
            $s->execute([trim((string)($_POST['username'] ?? ''))]);
            $row = $s->fetch();
            if ($row && password_verify((string)($_POST['password'] ?? ''), $row['pass_hash'])) {
                session_regenerate_id(true); $_SESSION['uid'] = (int)$row['id'];
                header('Location: ./'); exit;
            }
            record_hit('login'); usleep(600000);
            $err = 'Wrong username or password.';
        }
    }
    page_head('Sign in');
    echo '<main class="narrow"><h1>Back office sign in</h1>';
    if ($err) echo '<p class="err">' . h($err) . '</p>';
    echo '<form method="post" class="stack"><input type="hidden" name="csrf" value="' . h(csrf()) . '">
      <label>Username<input name="username" required autocomplete="username"></label>
      <label>Password<input name="password" type="password" required autocomplete="current-password"></label>
      <button class="btn">Sign in</button></form></main>';
    page_foot(); exit;
}

$isAdmin = $me['role'] === 'admin';
$scope = $isAdmin ? '' : (string)$me['constituency'];   // coordinators see only their constituency
$flash = '';

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Session expired. Go back and reload the page.'); }
    $a = $_POST['action'] ?? '';
    if ($a === 'update' && in_array($_POST['table'] ?? '', ['agents', 'volunteers', 'pledges'], true)) {
        $t = $_POST['table'];
        if ($t === 'pledges' && !$isAdmin) { http_response_code(403); exit; }
        $allowed = $t === 'agents' ? AGENT_STATUSES : ($t === 'volunteers' ? VOL_STATUSES : PLEDGE_STATUSES);
        $st = in_array($_POST['status'] ?? '', $allowed, true) ? $_POST['status'] : 'New';
        $sql = "UPDATE $t SET status = ?, notes = ?, updated_at = ?, updated_by = ? WHERE id = ?";
        $args = [$st, mb_substr((string)($_POST['notes'] ?? ''), 0, 2000), now(), $me['username'], (int)($_POST['id'] ?? 0)];
        if ($scope && $t !== 'pledges') { $sql .= ' AND constituency = ?'; $args[] = $scope; }
        db()->prepare($sql)->execute($args);
        $flash = 'Saved.';
    }
    if ($a === 'delete' && $isAdmin && in_array($_POST['table'] ?? '', ['agents', 'volunteers', 'pledges'], true)) {
        db()->prepare('DELETE FROM ' . $_POST['table'] . ' WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        $flash = 'Deleted.';
    }
    if ($a === 'adduser' && $isAdmin) {
        $u = trim((string)($_POST['username'] ?? '')); $pw = (string)($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'coordinator';
        $c = array_key_exists($_POST['constituency'] ?? '', CONSTITUENCIES) ? $_POST['constituency'] : '';
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $u)) $flash = 'Username: 3 to 60 letters, numbers, dot, dash or underscore.';
        elseif (strlen($pw) < 10) $flash = 'Password must be at least 10 characters.';
        elseif ($role === 'coordinator' && $c === '') $flash = 'Choose the constituency this coordinator looks after.';
        else {
            try { db()->prepare('INSERT INTO users (username, pass_hash, role, constituency, created_at) VALUES (?,?,?,?,?)')->execute([$u, password_hash($pw, PASSWORD_DEFAULT), $role, $role === 'admin' ? '' : $c, now()]); $flash = 'Account created for ' . $u . '.'; }
            catch (Throwable $e) { $flash = 'That username is already taken.'; }
        }
    }
    if ($a === 'deluser' && $isAdmin && (int)($_POST['id'] ?? 0) !== (int)$me['id']) {
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([(int)$_POST['id']]);
        $flash = 'Account removed.';
    }
    if ($a === 'password') {
        $s = db()->prepare('SELECT pass_hash FROM users WHERE id = ?'); $s->execute([$me['id']]);
        $pw = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), (string)$s->fetchColumn())) $flash = 'Your current password is wrong.';
        elseif (strlen($pw) < 10) $flash = 'New password must be at least 10 characters.';
        else { db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $me['id']]); $flash = 'Password changed.'; }
    }
}

/* ---------- helpers for lists ---------- */
function filters(string $table, string $scope): array {
    $w = []; $args = [];
    $c = $scope ?: (array_key_exists($_GET['c'] ?? '', CONSTITUENCIES) ? $_GET['c'] : '');
    if ($c && $table !== 'pledges') { $w[] = 'constituency = ?'; $args[] = $c; }
    if (!empty($_GET['w']) && $table !== 'pledges') { $w[] = 'ward = ?'; $args[] = (string)$_GET['w']; }
    if (!empty($_GET['s'])) { $w[] = 'status = ?'; $args[] = (string)$_GET['s']; }
    if (!empty($_GET['q'])) {
        $q = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$_GET['q']) . '%';
        $cols = $table === 'pledges' ? ['name', 'phone'] : ($table === 'agents' ? ['name', 'phone', 'station'] : ['name', 'phone', 'village']);
        $w[] = '(' . implode(' OR ', array_map(fn($x) => "$x LIKE ?", $cols)) . ')';
        foreach ($cols as $_) $args[] = $q;
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $args];
}
function csv_cell($v): string {
    $v = (string)$v;
    if ($v !== '' && strpbrk($v[0], '=+-@') !== false) $v = "'" . $v;   // stop spreadsheet formulas
    return '"' . str_replace('"', '""', $v) . '"';
}
function export_csv(string $name, array $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . gmdate('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    if ($rows) { echo implode(',', array_map('csv_cell', array_keys($rows[0]))) . "\r\n"; foreach ($rows as $r) echo implode(',', array_map('csv_cell', $r)) . "\r\n"; }
    exit;
}
function status_form(string $table, array $r, array $statuses): string {
    $o = '';
    foreach ($statuses as $s) $o .= '<option' . ($s === $r['status'] ? ' selected' : '') . '>' . h($s) . '</option>';
    return '<form method="post" class="row-edit"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="update"><input type="hidden" name="table" value="' . $table . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
        . '<select name="status">' . $o . '</select><input name="notes" value="' . h($r['notes'] ?? '') . '" placeholder="Notes"><button class="btn sm">Save</button></form>';
}
function filter_bar(string $p, string $scope, array $statuses, bool $wards = true): string {
    $o = '<form method="get" class="filters"><input type="hidden" name="p" value="' . h($p) . '">';
    if ($wards) {
        if (!$scope) {
            $o .= '<select name="c"><option value="">All constituencies</option>';
            foreach (CONSTITUENCIES as $c => $_) $o .= '<option' . (($_GET['c'] ?? '') === $c ? ' selected' : '') . '>' . h($c) . '</option>';
            $o .= '</select>';
        }
        $o .= '<select name="w"><option value="">All wards</option>';
        foreach (CONSTITUENCIES as $c => $ws) { if ($scope && $c !== $scope) continue; foreach ($ws as $w) $o .= '<option' . (($_GET['w'] ?? '') === $w ? ' selected' : '') . '>' . h($w) . '</option>'; }
        $o .= '</select>';
    }
    $o .= '<select name="s"><option value="">All statuses</option>';
    foreach ($statuses as $s) $o .= '<option' . (($_GET['s'] ?? '') === $s ? ' selected' : '') . '>' . h($s) . '</option>';
    $o .= '</select><input name="q" value="' . h($_GET['q'] ?? '') . '" placeholder="Search name or phone"><button class="btn sm">Filter</button>';
    $qs = $_GET; $qs['export'] = 1;
    $o .= '<a class="btn sm ghost" href="?' . h(http_build_query($qs)) . '">Download CSV</a></form>';
    return $o;
}

/* ---------- CSV exports (before any HTML) ---------- */
if (!empty($_GET['export'])) {
    if ($p === 'agents') { [$w, $a] = filters('agents', $scope); $s = db()->prepare("SELECT id, created_at, constituency, ward, station, station_code, stream, name, phone, registered_here, experience, training, status, notes, updated_by FROM agents$w ORDER BY constituency, ward, station, stream"); $s->execute($a); export_csv('agents', $s->fetchAll()); }
    if ($p === 'volunteers') { [$w, $a] = filters('volunteers', $scope); $s = db()->prepare("SELECT id, created_at, constituency, ward, station, stream, village, age, roles, message, name, phone, status, notes FROM volunteers$w ORDER BY constituency, ward"); $s->execute($a); export_csv('volunteers', $s->fetchAll()); }
    if ($p === 'pledges' && $isAdmin) { [$w, $a] = filters('pledges', ''); $s = db()->prepare("SELECT id, created_at, name, phone, amount_ksh, method, status, notes FROM pledges$w ORDER BY created_at DESC"); $s->execute($a); export_csv('pledges', $s->fetchAll()); }
    if ($p === 'gaps') {
        $rows = [];
        foreach (coverage_rows($scope) as $r) if (!$r['agents']) $rows[] = ['constituency' => $r['constituency'], 'ward' => $r['ward'], 'station' => $r['station'], 'centre_code' => $r['code'], 'stream' => $r['stream'], 'registered_voters' => $r['voters']];
        export_csv('streams-without-agents', $rows);
    }
}

/* Every stream in the IEBC list, with the agents registered for it. */
function coverage_rows(string $scope): array {
    $sql = 'SELECT ward, station, stream, name, phone, status FROM agents' . ($scope ? ' WHERE constituency = ?' : '');
    $s = db()->prepare($sql); $s->execute($scope ? [$scope] : []);
    $by = [];
    foreach ($s->fetchAll() as $a) if ($a['status'] !== 'Not available') $by[$a['ward'] . '|' . mb_strtolower($a['station']) . '|' . $a['stream']][] = $a;
    $rows = [];
    foreach (CONSTITUENCIES as $c => $ws) {
        if ($scope && $c !== $scope) continue;
        foreach ($ws as $w) foreach (stations()[$w] ?? [] as $ctr) {
            [$name, $code, $voters] = $ctr;
            foreach ($voters as $i => $v) $rows[] = ['constituency' => $c, 'ward' => $w, 'station' => $name, 'code' => $code, 'stream' => (string)($i + 1), 'voters' => $v, 'agents' => $by[$w . '|' . mb_strtolower($name) . '|' . ($i + 1)] ?? []];
        }
    }
    return $rows;
}

/* ---------- pages ---------- */
page_head('Back office', $me, $p);
if ($flash) echo '<p class="flash">' . h($flash) . '</p>';
echo '<main>';

if ($p === 'home') {
    $cov = coverage_rows($scope);
    $tot = []; $wardT = [];
    foreach ($cov as $r) {
        foreach ([$r['constituency'], 'ALL'] as $k) { $tot[$k]['streams'] = ($tot[$k]['streams'] ?? 0) + 1; $tot[$k]['covered'] = ($tot[$k]['covered'] ?? 0) + ($r['agents'] ? 1 : 0); }
        $wk = $r['constituency'] . '|' . $r['ward'];
        $wardT[$wk]['streams'] = ($wardT[$wk]['streams'] ?? 0) + 1; $wardT[$wk]['covered'] = ($wardT[$wk]['covered'] ?? 0) + ($r['agents'] ? 1 : 0);
    }
    $cnt = fn($t) => (int)db()->query("SELECT COUNT(*) FROM $t" . ($scope && $t !== 'pledges' ? ' WHERE constituency = ' . db()->quote($scope) : ''))->fetchColumn();
    $agents = $cnt('agents'); $vols = $cnt('volunteers');
    $all = $tot['ALL'] ?? ['streams' => 0, 'covered' => 0];
    echo '<h1>' . ($scope ? h($scope) : 'Nyamira County') . ' at a glance</h1><div class="tiles">';
    echo '<div><b>' . number_format($agents) . '</b><span>agents registered</span></div>';
    echo '<div><b>' . number_format($all['covered']) . ' / ' . number_format($all['streams']) . '</b><span>streams with an agent</span></div>';
    echo '<div><b>' . number_format($all['streams'] - $all['covered']) . '</b><span>streams still without an agent</span></div>';
    echo '<div><b>' . number_format($vols) . '</b><span>volunteers</span></div>';
    if ($isAdmin) { $pl = db()->query('SELECT COUNT(*) n, COALESCE(SUM(amount_ksh),0) s FROM pledges')->fetch(); echo '<div><b>Ksh ' . number_format((int)$pl['s']) . '</b><span>pledged by ' . (int)$pl['n'] . ' people</span></div>'; }
    echo '</div><h2>Agent coverage by ward</h2><table><thead><tr><th>Constituency</th><th>Ward</th><th>Streams</th><th>With agent</th><th>Coverage</th><th></th></tr></thead><tbody>';
    foreach ($wardT as $k => $v) {
        [$c, $w] = explode('|', $k); $pc = $v['streams'] ? round(100 * $v['covered'] / $v['streams']) : 0;
        echo '<tr><td>' . h($c) . '</td><td>' . h($w) . '</td><td>' . $v['streams'] . '</td><td>' . $v['covered'] . '</td><td><span class="bar"><i style="width:' . $pc . '%"></i></span> ' . $pc . '%</td><td><a href="?p=gaps&w=' . urlencode($w) . '">See streams</a></td></tr>';
    }
    echo '</tbody></table>';
}

if ($p === 'gaps') {
    $wSel = (string)($_GET['w'] ?? '');
    echo '<h1>Streams and their agents</h1><form method="get" class="filters"><input type="hidden" name="p" value="gaps"><select name="w"><option value="">All wards</option>';
    foreach (CONSTITUENCIES as $c => $ws) { if ($scope && $c !== $scope) continue; foreach ($ws as $w) echo '<option' . ($wSel === $w ? ' selected' : '') . '>' . h($w) . '</option>'; }
    echo '</select><label class="chk"><input type="checkbox" name="only" value="1"' . (!empty($_GET['only']) ? ' checked' : '') . '> Only streams without an agent</label><button class="btn sm">Show</button><a class="btn sm ghost" href="?p=gaps&export=1">Download all gaps (CSV)</a></form>';
    echo '<table><thead><tr><th>Ward</th><th>Polling station</th><th>Code</th><th>Stream</th><th>Voters</th><th>Agent</th></tr></thead><tbody>';
    foreach (coverage_rows($scope) as $r) {
        if ($wSel && $r['ward'] !== $wSel) continue;
        if (!empty($_GET['only']) && $r['agents']) continue;
        $ag = $r['agents'] ? implode('<br>', array_map(fn($a) => h($a['name']) . ' · ' . h($a['phone']) . ' <small>(' . h($a['status']) . ')</small>', $r['agents'])) : '<span class="gap">No agent yet</span>';
        echo '<tr><td>' . h($r['ward']) . '</td><td>' . h($r['station']) . '</td><td>' . h($r['code']) . '</td><td>' . h($r['stream']) . '</td><td>' . (int)$r['voters'] . '</td><td>' . $ag . '</td></tr>';
    }
    echo '</tbody></table>';
}

if ($p === 'agents') {
    [$w, $a] = filters('agents', $scope);
    $s = db()->prepare("SELECT * FROM agents$w ORDER BY created_at DESC LIMIT 500"); $s->execute($a);
    echo '<h1>Polling station agents</h1>' . filter_bar('agents', $scope, AGENT_STATUSES);
    echo '<table><thead><tr><th>Registered</th><th>Name and phone</th><th>Ward</th><th>Polling station</th><th>Stream</th><th>Details</th><th>Status and notes</th>' . ($isAdmin ? '<th></th>' : '') . '</tr></thead><tbody>';
    foreach ($s->fetchAll() as $r) {
        echo '<tr><td>' . h(substr($r['created_at'], 0, 10)) . '</td><td><b>' . h($r['name']) . '</b><br><a href="tel:' . h(preg_replace('/[^\d+]/', '', $r['phone'])) . '">' . h($r['phone']) . '</a></td><td>' . h($r['ward']) . '<br><small>' . h($r['constituency']) . '</small></td><td>' . h($r['station']) . ($r['station_code'] ? '<br><small>' . h($r['station_code']) . '</small>' : '') . '</td><td>' . h($r['stream']) . '</td>'
            . '<td><small>Registered here: ' . h($r['registered_here'] ?: '-') . '<br>Before: ' . h($r['experience'] ?: '-') . '<br>Training: ' . h($r['training'] ?: '-') . '</small></td><td>' . status_form('agents', $r, AGENT_STATUSES) . '</td>' . ($isAdmin ? '<td>' . delete_btn('agents', (int)$r['id']) . '</td>' : '') . '</tr>';
    }
    echo '</tbody></table><p class="muted">Showing the latest 500. Use the filters or download the CSV for everything.</p>';
}

if ($p === 'volunteers') {
    [$w, $a] = filters('volunteers', $scope);
    $s = db()->prepare("SELECT * FROM volunteers$w ORDER BY created_at DESC LIMIT 500"); $s->execute($a);
    echo '<h1>Volunteers</h1>' . filter_bar('volunteers', $scope, VOL_STATUSES);
    echo '<table><thead><tr><th>Registered</th><th>Name and phone</th><th>Ward</th><th>Village</th><th>How they will help</th><th>Top priority</th><th>Status and notes</th>' . ($isAdmin ? '<th></th>' : '') . '</tr></thead><tbody>';
    foreach ($s->fetchAll() as $r) {
        echo '<tr><td>' . h(substr($r['created_at'], 0, 10)) . '</td><td><b>' . h($r['name']) . '</b><br><a href="tel:' . h(preg_replace('/[^\d+]/', '', $r['phone'])) . '">' . h($r['phone']) . '</a><br><small>' . h($r['age']) . '</small></td><td>' . h($r['ward']) . '<br><small>' . h($r['constituency']) . '</small>' . ($r['station'] ? '<br><small>' . h($r['station']) . ($r['stream'] ? ' · stream ' . h($r['stream']) : '') . '</small>' : '') . '</td><td>' . h($r['village']) . '</td><td><small>' . h($r['roles']) . '</small></td><td><small>' . h($r['message']) . '</small></td><td>' . status_form('volunteers', $r, VOL_STATUSES) . '</td>' . ($isAdmin ? '<td>' . delete_btn('volunteers', (int)$r['id']) . '</td>' : '') . '</tr>';
    }
    echo '</tbody></table>';
}

if ($p === 'pledges' && $isAdmin) {
    [$w, $a] = filters('pledges', '');
    $s = db()->prepare("SELECT * FROM pledges$w ORDER BY created_at DESC LIMIT 500"); $s->execute($a);
    echo '<h1>Pledges</h1>' . filter_bar('pledges', '', PLEDGE_STATUSES, false);
    echo '<table><thead><tr><th>Date</th><th>Name and phone</th><th>Amount (Ksh)</th><th>Method</th><th>Status and notes</th><th></th></tr></thead><tbody>';
    foreach ($s->fetchAll() as $r) echo '<tr><td>' . h(substr($r['created_at'], 0, 10)) . '</td><td><b>' . h($r['name']) . '</b><br>' . h($r['phone']) . '</td><td>' . number_format((int)$r['amount_ksh']) . '</td><td>' . h($r['method']) . '</td><td>' . status_form('pledges', $r, PLEDGE_STATUSES) . '</td><td>' . delete_btn('pledges', (int)$r['id']) . '</td></tr>';
    echo '</tbody></table>';
}

if ($p === 'account') {
    echo '<h1>My account</h1><p>Signed in as <b>' . h($me['username']) . '</b> (' . h($me['role']) . ($me['constituency'] ? ', ' . h($me['constituency']) : '') . ').</p>';
    echo '<form method="post" class="stack narrow"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="password"><label>Current password<input type="password" name="old" required autocomplete="current-password"></label><label>New password (at least 10 characters)<input type="password" name="new" required autocomplete="new-password"></label><button class="btn">Change password</button></form>';
    if ($isAdmin) {
        echo '<h2>Team accounts</h2><p class="muted">Coordinators see only the agents and volunteers in their constituency, and cannot see pledges. Administrators see everything.</p><table><thead><tr><th>Username</th><th>Role</th><th>Constituency</th><th>Created</th><th></th></tr></thead><tbody>';
        foreach (db()->query('SELECT * FROM users ORDER BY role, username') as $u) {
            $del = (int)$u['id'] === (int)$me['id'] ? '' : '<form method="post" onsubmit="return confirm(\'Remove this account?\')"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="deluser"><input type="hidden" name="id" value="' . (int)$u['id'] . '"><button class="btn sm ghost">Remove</button></form>';
            echo '<tr><td>' . h($u['username']) . '</td><td>' . h($u['role']) . '</td><td>' . h($u['constituency'] ?: 'All') . '</td><td>' . h(substr($u['created_at'], 0, 10)) . '</td><td>' . $del . '</td></tr>';
        }
        echo '</tbody></table><h2>Add a team member</h2><form method="post" class="stack narrow"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="adduser"><label>Username<input name="username" required></label><label>Password (at least 10 characters; give it to them in person)<input name="password" type="password" required autocomplete="new-password"></label><label>Role<select name="role"><option value="coordinator">Constituency coordinator</option><option value="admin">Administrator</option></select></label><label>Constituency (for coordinators)<select name="constituency"><option value="">Choose</option>';
        foreach (CONSTITUENCIES as $c => $_) echo '<option>' . h($c) . '</option>';
        echo '</select></label><button class="btn">Create account</button></form>';
    }
}
echo '</main>';
page_foot();

function delete_btn(string $t, int $id): string {
    return '<form method="post" onsubmit="return confirm(\'Delete this record permanently?\')"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="table" value="' . $t . '"><input type="hidden" name="id" value="' . $id . '"><button class="btn sm ghost" title="Delete">Delete</button></form>';
}

function page_head(string $title, ?array $me = null, string $p = ''): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . h($title) . ' · Nyakundi 2027</title><style>
:root{--blue:#13264d;--red:#c0272d;--gold:#d4b04c;--green:#2e7d4f;--ink:#1b2333;--muted:#5d6779;--line:#dde2ea;--bg:#f4f6f9}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:var(--bg)}
header{background:var(--blue);color:#fff;border-bottom:3px solid var(--red);padding:12px 20px;display:flex;flex-wrap:wrap;gap:10px 22px;align-items:center}
header .brand{font-weight:800;font-size:1.1rem;color:#fff;text-decoration:none}header .brand span{color:var(--gold)}
header nav{display:flex;flex-wrap:wrap;gap:4px 16px;flex:1}header nav a{color:#dfe6f3;text-decoration:none;padding:4px 0;border-bottom:2px solid transparent}header nav a.on,header nav a:hover{color:#fff;border-color:var(--gold)}
header .who{color:#c9d2e3;font-size:.9rem}header .who a{color:#fff}
main{max-width:1280px;margin:0 auto;padding:24px 20px 60px}main.narrow,.narrow{max-width:520px}
h1{font-size:1.7rem;margin:6px 0 16px;color:var(--blue)}h2{font-size:1.2rem;margin:30px 0 10px;color:var(--blue)}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.tiles div{background:#fff;border:1px solid var(--line);border-top:3px solid var(--gold);padding:14px 16px;display:grid}.tiles b{font-size:1.6rem;color:var(--blue)}.tiles span{color:var(--muted)}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);margin-top:12px;font-size:.93rem}th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{background:#eef1f6;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
small{color:var(--muted)}a{color:var(--blue)}.gap{color:var(--red);font-weight:600}
.bar{display:inline-block;width:110px;height:9px;background:#e5e9f0;vertical-align:middle}.bar i{display:block;height:100%;background:var(--green)}
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px}
input,select{font:inherit;padding:7px 9px;border:1px solid #c5ccd8;border-radius:3px;background:#fff;max-width:100%}
.stack{display:grid;gap:12px}.stack label{display:grid;gap:4px;font-weight:600}
.row-edit{display:flex;flex-wrap:wrap;gap:6px}.row-edit input{min-width:140px;flex:1}
.btn{font:inherit;font-weight:700;background:var(--red);color:#fff;border:0;padding:9px 16px;border-radius:3px;cursor:pointer;text-decoration:none;display:inline-block}.btn.sm{padding:6px 11px;font-size:.88rem}.btn.ghost{background:#fff;color:var(--blue);border:1px solid var(--blue)}
.flash{max-width:1280px;margin:14px auto 0;padding:10px 20px;background:#e8f4ec;border-left:4px solid var(--green)}.err{color:var(--red);font-weight:600}.muted{color:var(--muted)}.chk{display:flex;gap:6px;align-items:center}
@media (max-width:760px){table,thead,tbody,tr,td{display:block}thead{display:none}tr{border-bottom:2px solid var(--line);padding:6px 0}td{border:0;padding:4px 10px}}
</style></head><body><header><a class="brand" href="./">Nyakundi <span>2027</span> · Back office</a>';
    if ($me) {
        $tabs = ['home' => 'Overview', 'gaps' => 'Streams', 'agents' => 'Agents', 'volunteers' => 'Volunteers'];
        if ($me['role'] === 'admin') $tabs['pledges'] = 'Pledges';
        $tabs['account'] = $me['role'] === 'admin' ? 'Team' : 'My account';
        echo '<nav>'; foreach ($tabs as $k => $v) echo '<a href="?p=' . $k . '"' . ($p === $k ? ' class="on"' : '') . '>' . $v . '</a>'; echo '</nav>';
        echo '<span class="who">' . h($me['username']) . ' · <a href="?p=logout">Sign out</a></span>';
    }
    echo '</header>';
}
function page_foot(): void { echo '</body></html>'; }
