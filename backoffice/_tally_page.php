<?php
/* Tallying center pages (back office ?p=tally). Only the two back office administrators can reach this. */
declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_tally_page.php') { http_response_code(404); exit; }
require_once __DIR__ . '/_tally.php';

function eat(string $utc): string { return $utc === '' ? '' : gmdate('d M H:i', strtotime($utc . ' UTC') + 3 * 3600); }
function t_int($v): ?int { $v = trim((string)$v); return preg_match('/^\d{1,6}$/', $v) ? (int)$v : null; }
function t_go(string $q, string $msg = '', bool $bad = false): void {
    if ($msg !== '') { $_SESSION['tflash'] = $msg; $_SESSION['tflash_bad'] = $bad; }
    header('Location: ?p=tally' . $q); exit;
}
function t_status_tag(string $s): string {
    $c = ['Verified' => 'ok', 'Disputed' => 'bad', 'Received' => 'wait', 'Pending' => 'wait', 'Missing' => 'bad'][$s] ?? '';
    return '<span class="tag ' . $c . '">' . h($s) . '</span>';
}
function t_flags(string $flags): string {
    $o = '';
    foreach (array_filter(explode(',', $flags)) as $f) $o .= '<div class="err small">⚠ ' . h(FLAG_TEXT[$f] ?? $f) . '</div>';
    return $o;
}
function t_place(array $f): string {
    return '<b>' . h($f['station']) . '</b> · stream ' . (int)$f['stream'] . '<br><small>' . h($f['ward']) . ' ward, ' . h($f['constituency']) . ' · ' . h(TALLY_FORMS[$f['form']] ?? $f['form']) . '</small>';
}
function t_form_type(): string { $t = (string)($_GET['form'] ?? $_POST['form'] ?? 'GOV'); return isset(TALLY_FORMS[$t]) ? $t : 'GOV'; }

function tally_page(array $me): void {
    tally_schema();
    $user = (string)$me['username'];
    $home = tally_can($me, 'results') ? 'results' : (tally_can($me, 'enter') ? 'enter' : 'queue');
    $v = (string)($_GET['v'] ?? $home);
    $need = ['results' => 'results', 'export' => 'results', 'enter' => 'enter', 'queue' => 'check', 'check' => 'check', 'forms' => 'forms', 'form' => 'forms', 'missing' => 'missing', 'audit' => 'audit', 'setup' => 'setup'];
    if (!isset($need[$v]) || !tally_can($me, $need[$v])) $v = $home;
    $live = tally_meta('mode') === 'live';

    /* ----- the photo of a signed form, only for signed-in center users ----- */
    if (isset($_GET['photo'])) {
        $f = tally_form((int)$_GET['photo']);
        $path = $f && $f['photo_file'] !== '' ? tally_photo_dir() . '/' . basename($f['photo_file']) : '';
        if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext]);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline');
        readfile($path); exit;
    }

    /* ----- CSV of the current results (one row per stream form, superseded forms left out) ----- */
    if ($v === 'export') {
        $form = t_form_type(); $cands = tally_candidates($form);
        tally_audit($user, 'exported', 0, ['form' => $form]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tally-' . strtolower($form) . '-' . gmdate('Ymd-Hi') . '.csv"');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        $safe = fn($x) => is_string($x) && preg_match('/^[=+\-@\t\r]/', $x) ? "'" . $x : $x;
        fputcsv($out, array_merge(['form id', 'status', 'constituency', 'ward', 'polling station', 'centre code', 'stream', 'registered', 'serial no', 'total cast', 'rejected'], array_column($cands, 'name'), ['entered by', 'entered (Kenya time)', 'channel', 'warnings', 'accepted by', 'accepted note']));
        $s = db()->prepare('SELECT * FROM tally_forms WHERE form = ? AND superseded = 0 ORDER BY constituency, ward, station, stream'); $s->execute([$form]);
        foreach ($s->fetchAll() as $f) {
            $vt = tally_votes((int)$f['id']); $row = [$f['id'], $f['status'], $f['constituency'], $f['ward'], $f['station'], $f['centre_code'], $f['stream'], $f['registered'], $f['serial_no'], $f['total_cast'], $f['rejected']];
            foreach ($cands as $c) $row[] = $vt[(int)$c['id']] ?? '';
            $row = array_merge($row, [$f['entered_by'], eat($f['entered_at']), $f['channel'], implode('; ', array_map(fn($x) => FLAG_TEXT[$x] ?? $x, array_filter(explode(',', $f['flags'])))), $f['accepted_by'], (string)$f['accepted_note']]);
            fputcsv($out, array_map($safe, $row));
        }
        exit;
    }

    /* ----- actions ----- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        $perm = ['t_enter' => 'enter', 't_check' => 'check', 't_photo' => 'photo', 't_accept' => 'accept', 't_dispute' => 'dispute'][$a] ?? 'setup';
        if (!tally_can($me, $perm)) { tally_audit($user, 'refused', (int)($_POST['id'] ?? 0), ['action' => $a]); t_go('', 'Your role cannot do that.', true); }
        if (str_starts_with($a, 't_staff_')) tally_staff_action($user, $a);

        if ($a === 't_enter') {
            $form = t_form_type(); $ward = (string)($_POST['ward'] ?? ''); $code = (string)($_POST['centre'] ?? ''); $stream = (int)($_POST['stream'] ?? 0);
            $ctr = tally_find_centre($ward, $code); $cands = tally_candidates($form);
            $replaces = (int)($_POST['replaces'] ?? 0); $back = $replaces ? '&v=enter&replaces=' . $replaces : '&v=enter&form=' . $form;
            if (!$cands) t_go($back, 'Add the candidates for this form in Setup first.', true);
            if (!$ctr || $stream < 1 || $stream > count($ctr[2])) t_go($back, 'Choose the ward, polling station and stream.', true);
            $total = t_int($_POST['total_cast'] ?? ''); $rej = t_int($_POST['rejected'] ?? '');
            $votes = [];
            foreach ($cands as $c) { $n = t_int($_POST['votes'][$c['id']] ?? ''); if ($n === null) t_go($back, 'Enter the votes for every candidate (0 if none).', true); $votes[(int)$c['id']] = $n; }
            if ($total === null || $rej === null) t_go($back, 'Enter the total votes cast and the rejected votes.', true);
            $channel = isset(TALLY_CHANNELS[$_POST['channel'] ?? '']) ? $_POST['channel'] : 'clerk';
            [$photo, $sha, $perr] = tally_store_photo($_FILES['photo'] ?? []);
            if ($perr) t_go($back, $perr, true);
            if ($photo === '' && $channel === 'clerk') t_go($back, 'A form typed in the room needs the photo of the signed form. For an SMS or WhatsApp report without a photo, choose that channel.', true);
            $old = $replaces ? tally_form($replaces) : null;
            if ($replaces && (!$old || (int)$old['superseded'])) t_go('&v=forms', 'That form has already been replaced.', true);
            db()->beginTransaction();
            db()->prepare('INSERT INTO tally_forms (form, constituency, ward, station, centre_code, stream, registered, serial_no, total_cast, rejected, channel, entered_by, entered_at, photo_file, photo_sha, status, flags, accepted_by, accepted_note, superseded, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$form, ward_constituency($ward), $ward, $ctr[0], $code, $stream, (int)$ctr[2][$stream - 1], mb_substr(trim((string)($_POST['serial_no'] ?? '')), 0, 40), $total, $rej, $channel, $user, now(), $photo, $sha, 'Received', '', '', '', 0, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000)]);
            $id = (int)db()->lastInsertId();
            $ins = db()->prepare('INSERT INTO tally_votes (form_id, candidate_id, votes) VALUES (?,?,?)');
            foreach ($votes as $cid => $n) $ins->execute([$id, $cid, $n]);
            if ($old) {
                db()->prepare('UPDATE tally_forms SET superseded = 1 WHERE id = ?')->execute([$replaces]);
                tally_audit($user, 'replaced', $replaces, ['by_form' => $id, 'reason' => (string)($_POST['note'] ?? '')]);
            }
            tally_audit($user, 'entered', $id, ['station' => $ctr[0], 'stream' => $stream, 'total_cast' => $total, 'rejected' => $rej, 'votes' => $votes, 'channel' => $channel, 'photo_sha256' => $sha]);
            db()->commit();
            $f = tally_form($id); tally_recheck_stream($f); if ($old) tally_recheck_stream($old);
            $flags = tally_recheck($id);
            t_go('&v=form&id=' . $id, $flags ? 'Saved, with warnings. The form is marked Disputed until the director reviews it.' : 'Saved. The form now waits for a second person to check it against the photo.', (bool)$flags);
        }

        $id = (int)($_POST['id'] ?? 0); $f = $id ? tally_form($id) : null;
        if ($f && $a === 't_check') {
            $s = db()->prepare('SELECT COUNT(*) FROM tally_checks WHERE form_id = ? AND by_user = ?'); $s->execute([$id, $user]);
            if ($f['entered_by'] === $user) t_go('&v=form&id=' . $id, 'You typed this form in, so the check must be done by the other person.', true);
            if ((int)$s->fetchColumn() > 0) t_go('&v=form&id=' . $id, 'You have already checked this form.', true);
            $typed = ['total_cast' => t_int($_POST['total_cast'] ?? ''), 'rejected' => t_int($_POST['rejected'] ?? ''), 'votes' => []];
            $real = tally_votes($id); $match = $typed['total_cast'] === (int)$f['total_cast'] && $typed['rejected'] === (int)$f['rejected'];
            foreach ($real as $cid => $n) { $t = t_int($_POST['votes'][$cid] ?? ''); $typed['votes'][$cid] = $t; if ($t !== $n) $match = false; }
            db()->prepare('INSERT INTO tally_checks (form_id, by_user, at, typed, matches) VALUES (?,?,?,?,?)')->execute([$id, $user, now(), json_encode($typed), $match ? 1 : 0]);
            tally_audit($user, $match ? 'check_matched' : 'check_differed', $id, $typed);
            tally_recheck($id);
            t_go('&v=form&id=' . $id, $match ? 'Your figures match. The form is confirmed by two people.' : 'Your figures differ from the first entry. The form is now Disputed for the director to decide from the photo.', !$match);
        }
        if ($f && $a === 't_photo') {
            [$photo, $sha, $perr] = tally_store_photo($_FILES['photo'] ?? []);
            if ($perr || $photo === '') t_go('&v=form&id=' . $id, $perr ?: 'Choose the photo of the signed form.', true);
            if ($f['photo_file'] !== '') t_go('&v=form&id=' . $id, 'This form already has a photo. To change it, enter a replacement form.', true);
            db()->prepare('UPDATE tally_forms SET photo_file = ?, photo_sha = ? WHERE id = ?')->execute([$photo, $sha, $id]);
            tally_audit($user, 'photo_added', $id, ['photo_sha256' => $sha]);
            tally_recheck($id);
            t_go('&v=form&id=' . $id, 'Photo attached. Check that the figures on the photo match the entry.');
        }
        if ($f && $a === 't_accept') {
            $note = trim((string)($_POST['note'] ?? ''));
            if ($f['entered_by'] === $user) t_go('&v=form&id=' . $id, 'The person who typed the form in cannot accept it. The other person must review it.', true);
            if (mb_strlen($note) < 5) t_go('&v=form&id=' . $id, 'Write why the form is accepted (what was checked on the photo).', true);
            db()->prepare('UPDATE tally_forms SET accepted_by = ?, accepted_note = ? WHERE id = ?')->execute([$user, mb_substr($note, 0, 1000), $id]);
            tally_audit($user, 'accepted', $id, ['note' => $note, 'warnings' => $f['flags']]);
            tally_recheck($id);
            t_go('&v=form&id=' . $id, 'Accepted. The warnings stay on record.');
        }
        if ($f && $a === 't_dispute') {
            $note = trim((string)($_POST['note'] ?? ''));
            if (mb_strlen($note) < 5) t_go('&v=form&id=' . $id, 'Write the reason for the dispute.', true);
            $flags = array_unique(array_merge(array_filter(explode(',', $f['flags'])), ['manual']));
            db()->prepare('UPDATE tally_forms SET flags = ?, accepted_by = ?, accepted_note = ? WHERE id = ?')->execute([implode(',', $flags), '', '', $id]);
            tally_audit($user, 'disputed', $id, ['note' => $note]);
            tally_recheck($id);
            t_go('&v=form&id=' . $id, 'Marked as disputed. Refer it to the legal desk.', true);
        }
        if ($a === 't_cand_add') {
            $form = t_form_type(); $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') t_go('&v=setup', 'Enter the candidate name.', true);
            db()->prepare('INSERT INTO tally_candidates (form, name, party, sort, ours) VALUES (?,?,?,?,?)')->execute([$form, mb_substr($name, 0, 120), mb_substr(trim((string)($_POST['party'] ?? '')), 0, 120), (int)($_POST['sort'] ?? 0), isset($_POST['ours']) ? 1 : 0]);
            tally_audit($user, 'candidate_added', 0, ['form' => $form, 'name' => $name]);
            t_go('&v=setup', 'Candidate added.');
        }
        if ($a === 't_cand_del') {
            $cid = (int)($_POST['cid'] ?? 0);
            $s = db()->prepare('SELECT COUNT(*) FROM tally_votes WHERE candidate_id = ?'); $s->execute([$cid]);
            if ((int)$s->fetchColumn() > 0) t_go('&v=setup', 'This candidate already has votes on forms and cannot be removed.', true);
            db()->prepare('DELETE FROM tally_candidates WHERE id = ?')->execute([$cid]);
            tally_audit($user, 'candidate_removed', 0, ['id' => $cid]);
            t_go('&v=setup', 'Candidate removed.');
        }
        if ($a === 't_settings') {
            $lim = (float)($_POST['turnout_limit'] ?? 90); if ($lim < 50 || $lim > 100) $lim = 90;
            $close = trim((string)($_POST['polls_close'] ?? ''));
            $closeUtc = $close !== '' && strtotime($close . ' +03:00') ? gmdate('Y-m-d H:i:s', strtotime($close . ' +03:00')) : '';
            tally_set_meta('turnout_limit', (string)$lim); tally_set_meta('polls_close', $closeUtc);
            tally_audit($user, 'settings', 0, ['turnout_limit' => $lim, 'polls_close_utc' => $closeUtc]);
            foreach (db()->query('SELECT id FROM tally_forms')->fetchAll(PDO::FETCH_COLUMN) as $i) tally_recheck((int)$i);
            t_go('&v=setup', 'Settings saved and every form re-checked.');
        }
        if ($a === 't_clear' && !$live) {
            if (($_POST['confirm'] ?? '') !== 'CLEAR') t_go('&v=setup', 'Type CLEAR to confirm.', true);
            $n = (int)db()->query('SELECT COUNT(*) FROM tally_forms')->fetchColumn();
            foreach (['tally_votes', 'tally_checks', 'tally_forms'] as $t) db()->exec("DELETE FROM $t");
            tally_audit($user, 'rehearsal_cleared', 0, ['forms_removed' => $n]);
            t_go('&v=setup', 'Rehearsal results cleared (' . $n . ' forms). The audit log keeps a record of the rehearsal.');
        }
        if ($a === 't_golive' && !$live) {
            if (($_POST['confirm'] ?? '') !== 'LIVE') t_go('&v=setup', 'Type LIVE to confirm.', true);
            $n = (int)db()->query('SELECT COUNT(*) FROM tally_forms')->fetchColumn();
            foreach (['tally_votes', 'tally_checks', 'tally_forms'] as $t) db()->exec("DELETE FROM $t");
            tally_set_meta('mode', 'live');
            tally_audit($user, 'went_live', 0, ['rehearsal_forms_removed' => $n]);
            t_go('', 'Election day mode is on. Results can no longer be cleared.');
        }
        t_go('', 'Nothing was changed.', true);
    }

    /* ----- pages ----- */
    page_head('Tallying center', $me, 'tally');
    echo '<style>.cols>*,.two>*{min-width:0}.stack input,.stack select{width:100%}.two label{display:grid;gap:4px;font-weight:600}@media (max-width:760px){header nav{flex-basis:100%}}</style>';
    $msg = $_SESSION['tflash'] ?? ''; $bad = $_SESSION['tflash_bad'] ?? false; unset($_SESSION['tflash'], $_SESSION['tflash_bad']);
    if ($msg !== '') echo '<div class="flash' . ($bad ? ' bad' : '') . '">' . h($msg) . '</div>';
    echo '<main><h1>Tallying center ' . ($live ? '<span class="pill off">Election day</span>' : '<span class="pill test">Rehearsal</span>') . '</h1>';
    $queue = db()->prepare("SELECT COUNT(*) FROM tally_forms f WHERE superseded = 0 AND status = 'Received' AND entered_by <> ? AND NOT EXISTS (SELECT 1 FROM tally_checks c WHERE c.form_id = f.id AND c.by_user = ?)");
    $queue->execute([$user, $user]); $q = (int)$queue->fetchColumn();
    $disp = (int)db()->query("SELECT COUNT(*) FROM tally_forms WHERE superseded = 0 AND status = 'Disputed'")->fetchColumn();
    $all = ['results' => 'Results', 'enter' => 'Enter a form', 'queue' => 'Check forms' . ($q ? " ($q)" : ''), 'forms' => 'All forms' . ($disp ? " · $disp disputed" : ''), 'missing' => 'Missing streams', 'audit' => 'Audit log', 'setup' => 'Setup'];
    $sub = array_filter($all, fn($k) => tally_can($me, $need[$k]), ARRAY_FILTER_USE_KEY);
    echo '<nav class="subnav">'; foreach ($sub as $k => $t) echo '<a href="?p=tally&v=' . $k . '"' . ($v === $k || ($v === 'form' && $k === 'forms') || ($v === 'check' && $k === 'queue') ? ' class="on"' : '') . '>' . h($t) . '</a>'; echo '</nav>';
    $fn = 'tally_view_' . $v;
    if (function_exists($fn)) $fn($me); else tally_view_results($me);
    echo '</main>'; page_foot(); exit;
}

function t_form_switch(string $v, string $form): string {
    $o = '<div class="filters">'; foreach (TALLY_FORMS as $k => $t) $o .= '<a class="btn sm' . ($k === $form ? '' : ' ghost') . '" href="?p=tally&v=' . $v . '&form=' . $k . '">' . h($t) . '</a>'; return $o . '</div>';
}

function tally_view_results(array $me): void {
    $form = t_form_type(); $cands = tally_candidates($form);
    echo t_form_switch('results', $form);
    if (!$cands) { echo '<div class="card help"><h2>Start here</h2><ol><li>Open <a href="?p=tally&v=setup">Setup</a> and add the candidates for each form, in the order they appear on the IEBC form.</li><li>Set the high turnout warning and the time polls close.</li><li>Use <a href="?p=tally&v=enter">Enter a form</a> to type in a rehearsal form with its photo.</li><li>The other person opens <a href="?p=tally&v=queue">Check forms</a> and re-types the figures from the photo without seeing the first entry.</li></ol></div>'; return; }
    $rows = db()->prepare('SELECT * FROM tally_forms WHERE form = ? AND superseded = 0'); $rows->execute([$form]); $forms = $rows->fetchAll();
    $by = []; $tot = ['streams' => [], 'verified' => 0, 'cast' => 0, 'reg' => 0, 'rej' => 0]; $cv = []; $cvAll = [];
    $count = ['Received' => 0, 'Pending' => 0, 'Verified' => 0, 'Disputed' => 0];
    foreach ($forms as $f) {
        $key = $f['ward'] . '|' . $f['centre_code'] . '|' . $f['stream'];
        $tot['streams'][$key] = 1; $count[$f['status']] = ($count[$f['status']] ?? 0) + 1;
        $by[$f['constituency']]['in'][$key] = 1; $by[$f['constituency']]['wards'][$f['ward']][$key] = 1;
        $vt = tally_votes((int)$f['id']);
        foreach ($vt as $cid => $n) $cvAll[$cid] = ($cvAll[$cid] ?? 0) + $n;
        if ($f['status'] === 'Verified' || $f['status'] === 'Pending') {
            $tot['verified']++; $tot['cast'] += (int)$f['total_cast']; $tot['reg'] += (int)$f['registered']; $tot['rej'] += (int)$f['rejected'];
            foreach ($vt as $cid => $n) $cv[$cid] = ($cv[$cid] ?? 0) + $n;
            $by[$f['constituency']]['ok'] = ($by[$f['constituency']]['ok'] ?? 0) + 1;
            foreach ($vt as $cid => $n) $by[$f['constituency']]['votes'][$cid] = ($by[$f['constituency']]['votes'][$cid] ?? 0) + $n;
        }
    }
    $all = tally_stream_count();
    echo '<div class="tiles"><div><b>' . count($tot['streams']) . ' / ' . $all . '</b><span>streams reported</span></div><div><b>' . $count['Verified'] . '</b><span>verified against the signed form</span></div><div><b>' . ($count['Received'] + $count['Pending']) . '</b><span>waiting: second check or photo</span></div><div><b class="' . ($count['Disputed'] ? 'gap' : '') . '">' . $count['Disputed'] . '</b><span>disputed</span></div><div><b>' . ($tot['reg'] ? round(100 * $tot['cast'] / $tot['reg'], 1) . '%' : '–') . '</b><span>turnout in confirmed streams</span></div></div>';
    $sum = array_sum($cv);
    echo '<h2>Totals from confirmed forms</h2><p class="muted small">Only forms confirmed by two people are counted here. Forms waiting for a check or in dispute are shown separately and never added in.</p><table><thead><tr><th>Candidate</th><th>Votes (confirmed)</th><th>Share</th><th>Including unconfirmed</th></tr></thead><tbody>';
    foreach ($cands as $c) { $n = $cv[(int)$c['id']] ?? 0; echo '<tr><td>' . ((int)$c['ours'] ? '<b>' . h($c['name']) . '</b>' : h($c['name'])) . ' <small>' . h($c['party']) . '</small></td><td><b>' . number_format($n) . '</b></td><td>' . ($sum ? round(100 * $n / $sum, 1) . '%' : '–') . ' <span class="bar"><i style="width:' . ($sum ? round(100 * $n / $sum) : 0) . '%"></i></span></td><td><small>' . number_format($cvAll[(int)$c['id']] ?? 0) . '</small></td></tr>'; }
    echo '<tr><td><small>Rejected</small></td><td>' . number_format($tot['rej']) . '</td><td></td><td></td></tr></tbody></table>';
    echo '<h2>Reporting by constituency and ward</h2><table><thead><tr><th>Area</th><th>Streams reported</th><th>Confirmed</th>';
    foreach ($cands as $c) echo '<th>' . h($c['name']) . '</th>'; echo '</tr></thead><tbody>';
    foreach (CONSTITUENCIES as $con => $ws) {
        $n = tally_stream_count($con); $in = count($by[$con]['in'] ?? []);
        echo '<tr><td><b>' . h($con) . '</b></td><td>' . $in . ' / ' . $n . ' <span class="bar"><i style="width:' . ($n ? round(100 * $in / $n) : 0) . '%"></i></span></td><td>' . (int)($by[$con]['ok'] ?? 0) . '</td>';
        foreach ($cands as $c) echo '<td>' . number_format($by[$con]['votes'][(int)$c['id']] ?? 0) . '</td>'; echo '</tr>';
        foreach ($ws as $w) { $wn = tally_stream_count('', $w); $wi = count($by[$con]['wards'][$w] ?? []); echo '<tr><td style="padding-left:26px">' . h($w) . '</td><td><small>' . $wi . ' / ' . $wn . '</small></td><td colspan="' . (1 + count($cands)) . '"></td></tr>'; }
    }
    echo '</tbody></table><p><a class="btn ghost sm" href="?p=tally&v=export&form=' . $form . '">Download results (CSV)</a></p>';
}

function tally_view_enter(array $me): void {
    $replaces = (int)($_GET['replaces'] ?? 0); $old = $replaces ? tally_form($replaces) : null;
    $form = $old ? $old['form'] : t_form_type(); $cands = tally_candidates($form);
    if (!$old) echo t_form_switch('enter', $form);
    if (!$cands) { echo '<p class="err">Add the candidates for ' . h(TALLY_FORMS[$form]) . ' in <a href="?p=tally&v=setup">Setup</a> first.</p>'; return; }
    $data = []; foreach (tally_streams() as $w => $cs) $data[$w] = array_map(fn($c) => [$c[0], $c[1], $c[2]], $cs);
    echo '<div class="cols"><form method="post" enctype="multipart/form-data" class="card stack" id="tEnter"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="t_enter"><input type="hidden" name="form" value="' . h($form) . '">';
    if ($old) echo '<input type="hidden" name="replaces" value="' . $replaces . '"><p class="err">This entry replaces form #' . $replaces . ' (' . h($old['station']) . ', stream ' . (int)$old['stream'] . '). The old entry stays on record, marked as replaced.</p>';
    echo '<h2>' . h(TALLY_FORMS[$form]) . '</h2><div class="two"><label>Ward<select name="ward" id="tWard" required><option value="">Choose</option>';
    foreach (CONSTITUENCIES as $c => $ws) { echo '<optgroup label="' . h($c) . '">'; foreach ($ws as $w) echo '<option' . ($old && $old['ward'] === $w ? ' selected' : '') . '>' . h($w) . '</option>'; echo '</optgroup>'; }
    echo '</select></label><label>Polling station<select name="centre" id="tCentre" required></select></label><label>Stream<select name="stream" id="tStream" required></select></label><label>Form serial number <small>(printed on the form)</small><input name="serial_no" maxlength="40"></label></div>';
    echo '<p class="muted small" id="tReg"></p><div class="two"><label>Total votes cast<input name="total_cast" inputmode="numeric" pattern="\d*" required data-t="total"></label><label>Rejected votes<input name="rejected" inputmode="numeric" pattern="\d*" required data-t="rej"></label></div><h2>Votes per candidate</h2><div class="two">';
    foreach ($cands as $c) echo '<label>' . h($c['name']) . ' <small>' . h($c['party']) . '</small><input name="votes[' . (int)$c['id'] . ']" inputmode="numeric" pattern="\d*" required data-t="v"></label>';
    echo '</div><p id="tSum" class="small"></p><div class="two"><label>How the result came in<select name="channel">'; foreach (TALLY_CHANNELS as $k => $t) if ($k !== 'app') echo '<option value="' . $k . '">' . h($t) . '</option>'; echo '</select></label>';
    echo '<label>Photo of the signed form<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment"></label></div><label>Note <small>(optional' . ($old ? '; give the reason for the replacement' : '') . ')</small><textarea name="note" rows="2" maxlength="1000"' . ($old ? ' required' : '') . '></textarea></label><button class="btn">Save form</button></form>';
    echo '<div class="card help"><h2>How to enter a form</h2><ol><li>Choose the ward, station and stream. The number of registered voters fills in from the IEBC list.</li><li>Type the figures exactly as written on the signed form. Do not correct anything: if the form looks wrong, type it as it is and add a note.</li><li>Attach the photo of the signed form. An SMS or WhatsApp report can be saved without a photo; it stays Pending until the photo arrives.</li><li>After saving, the other person checks the figures against the photo without seeing yours.</li></ol></div></div>';
    $preset = $old ? json_encode([$old['centre_code'], (int)$old['stream']]) : 'null';
    echo '<script>(function(){var D=' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ',P=' . $preset . ',w=document.getElementById("tWard"),c=document.getElementById("tCentre"),s=document.getElementById("tStream"),r=document.getElementById("tReg"),f=document.getElementById("tEnter");
function opt(el,list){el.innerHTML="";list.forEach(function(x){var o=document.createElement("option");o.value=x[0];o.textContent=x[1];el.appendChild(o);});}
function centres(){var l=(D[w.value]||[]).map(function(x){return [x[1],x[0]];});opt(c,[["","Choose"]].concat(l));if(P){c.value=P[0];}streams();}
function streams(){var x=(D[w.value]||[]).filter(function(y){return y[1]===c.value;})[0];if(!x){opt(s,[]);r.textContent="";return;}opt(s,x[2].map(function(n,i){return [i+1,"Stream "+(i+1)+" · "+n+" registered"];}));if(P){s.value=P[1];P=null;}reg();}
function reg(){var x=(D[w.value]||[]).filter(function(y){return y[1]===c.value;})[0];if(x)r.textContent="Registered voters in this stream: "+x[2][s.value-1]+" · centre code "+x[1];sum();}
function num(e){return e.value===""?0:parseInt(e.value,10)||0;}
function sum(){var v=0;f.querySelectorAll("[data-t=v]").forEach(function(e){v+=num(e);});var t=num(f.querySelector("[data-t=total]")),j=num(f.querySelector("[data-t=rej]")),o=document.getElementById("tSum");if(!f.querySelector("[data-t=total]").value){o.textContent="";return;}var ok=v+j===t;o.className="small "+(ok?"":"err");o.textContent="Candidates "+v+" + rejected "+j+" = "+(v+j)+(ok?" (matches the total)":" (does not match the total of "+t+": check the form)");}
w.addEventListener("change",centres);c.addEventListener("change",streams);s.addEventListener("change",reg);f.addEventListener("input",sum);if(w.value)centres();})();</script>';
}

function tally_view_queue(array $me): void {
    $user = (string)$me['username'];
    $s = db()->prepare("SELECT * FROM tally_forms f WHERE superseded = 0 AND status = 'Received' AND entered_by <> ? AND NOT EXISTS (SELECT 1 FROM tally_checks c WHERE c.form_id = f.id AND c.by_user = ?) ORDER BY entered_at");
    $s->execute([$user, $user]); $rows = $s->fetchAll();
    echo '<p class="muted">Forms typed in by the other person, waiting for your blind check. You re-type the figures from the photo; you will not see the first entry until you save.</p>';
    if (!$rows) { echo '<p><b>Nothing waiting for you.</b></p>'; return; }
    echo '<table><thead><tr><th>Stream</th><th>Entered</th><th></th></tr></thead><tbody>';
    foreach ($rows as $f) echo '<tr><td>' . t_place($f) . '</td><td><small>' . h($f['entered_by']) . ' · ' . eat($f['entered_at']) . '</small></td><td><a class="btn sm" href="?p=tally&v=check&id=' . (int)$f['id'] . '">Check</a></td></tr>';
    echo '</tbody></table>';
}

function tally_view_check(array $me): void {
    $f = tally_form((int)($_GET['id'] ?? 0));
    if (!$f) { echo '<p class="err">Form not found.</p>'; return; }
    if ($f['entered_by'] === $me['username']) { echo '<p class="err">You typed this form in, so the other person must check it.</p><p><a href="?p=tally&v=form&id=' . (int)$f['id'] . '">Open the form</a></p>'; return; }
    $cands = tally_candidates($f['form']); $real = tally_votes((int)$f['id']);
    echo '<div class="cols"><div class="card">' . ($f['photo_file'] !== '' ? '<a href="?p=tally&photo=' . (int)$f['id'] . '" target="_blank" rel="noopener"><img src="?p=tally&photo=' . (int)$f['id'] . '" alt="Signed form" style="width:100%;border:1px solid var(--line)"></a><p class="small muted">Tap the photo to open it full size.</p>' : '<p class="err">No photo yet. This came in by ' . h(TALLY_CHANNELS[$f['channel']] ?? $f['channel']) . '. Check the figures against the original message.</p>') . '</div>';
    echo '<form method="post" class="card stack"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="t_check"><input type="hidden" name="id" value="' . (int)$f['id'] . '"><p>' . t_place($f) . '</p><p class="muted small">Type what you read on the form.</p>';
    echo '<div class="two"><label>Total votes cast<input name="total_cast" inputmode="numeric" pattern="\d*" required autocomplete="off"></label><label>Rejected votes<input name="rejected" inputmode="numeric" pattern="\d*" required autocomplete="off"></label></div>';
    foreach ($cands as $c) if (array_key_exists((int)$c['id'], $real)) echo '<label>' . h($c['name']) . '<input name="votes[' . (int)$c['id'] . ']" inputmode="numeric" pattern="\d*" required autocomplete="off"></label>';
    echo '<button class="btn">Save my check</button></form></div>';
}

function tally_view_forms(array $me): void {
    $st = (string)($_GET['status'] ?? ''); $ward = (string)($_GET['ward'] ?? ''); $form = t_form_type();
    echo t_form_switch('forms', $form);
    $sql = 'SELECT * FROM tally_forms WHERE form = ?'; $a = [$form];
    if ($st === 'Superseded') $sql .= ' AND superseded = 1'; else { $sql .= ' AND superseded = 0'; if (in_array($st, TALLY_STATUSES, true)) { $sql .= ' AND status = ?'; $a[] = $st; } }
    if ($ward !== '' && ward_constituency($ward) !== '') { $sql .= ' AND ward = ?'; $a[] = $ward; }
    $s = db()->prepare($sql . ' ORDER BY (status = \'Disputed\') DESC, entered_at DESC LIMIT 1000'); $s->execute($a); $rows = $s->fetchAll();
    echo '<form class="filters"><input type="hidden" name="p" value="tally"><input type="hidden" name="v" value="forms"><input type="hidden" name="form" value="' . h($form) . '"><select name="status"><option value="">All current forms</option>';
    foreach (['Received', 'Pending', 'Verified', 'Disputed', 'Superseded'] as $x) echo '<option' . ($st === $x ? ' selected' : '') . '>' . $x . '</option>';
    echo '</select><select name="ward"><option value="">All wards</option>'; foreach (CONSTITUENCIES as $ws) foreach ($ws as $w) echo '<option' . ($ward === $w ? ' selected' : '') . '>' . h($w) . '</option>';
    echo '</select><button class="btn sm ghost">Show</button></form>';
    if (!$rows) { echo '<p class="muted">No forms.</p>'; return; }
    echo '<table><thead><tr><th>#</th><th>Stream</th><th>Status</th><th>Cast / registered</th><th>Entered</th></tr></thead><tbody>';
    foreach ($rows as $f) echo '<tr><td><a href="?p=tally&v=form&id=' . (int)$f['id'] . '">#' . (int)$f['id'] . '</a></td><td>' . t_place($f) . '</td><td>' . ((int)$f['superseded'] ? '<span class="tag">Replaced</span>' : t_status_tag($f['status'])) . t_flags($f['flags']) . '</td><td>' . (int)$f['total_cast'] . ' / ' . (int)$f['registered'] . '</td><td><small>' . h($f['entered_by']) . '<br>' . eat($f['entered_at']) . '</small></td></tr>';
    echo '</tbody></table>';
}

function tally_view_form(array $me): void {
    $id = (int)($_GET['id'] ?? 0); $f = tally_form($id);
    if (!$f) { echo '<p class="err">Form not found.</p>'; return; }
    $user = (string)$me['username']; $cands = tally_candidates($f['form']); $vt = tally_votes($id);
    $s = db()->prepare('SELECT * FROM tally_checks WHERE form_id = ? ORDER BY id'); $s->execute([$id]); $checks = $s->fetchAll();
    $iChecked = (bool)array_filter($checks, fn($c) => $c['by_user'] === $user);
    // Until the other person has done their blind check, they must not see the figures.
    $hide = !(int)$f['superseded'] && $f['entered_by'] !== $user && !$iChecked && $f['status'] === 'Received';
    echo '<p>' . t_place($f) . '</p><p>' . ((int)$f['superseded'] ? '<span class="tag">Replaced by a later entry</span>' : t_status_tag($f['status'])) . ' ' . t_flags($f['flags']) . '</p>';
    echo '<div class="cols"><div>';
    if ($hide) echo '<div class="card"><p><b>Figures hidden until you do your blind check.</b></p><a class="btn" href="?p=tally&v=check&id=' . $id . '">Check this form</a></div>';
    else {
        echo '<table><tbody><tr><th>Registered</th><td>' . (int)$f['registered'] . '</td></tr><tr><th>Total cast</th><td><b>' . (int)$f['total_cast'] . '</b>' . ((int)$f['registered'] ? ' <small>(' . round(100 * (int)$f['total_cast'] / (int)$f['registered'], 1) . '% turnout)</small>' : '') . '</td></tr><tr><th>Rejected</th><td>' . (int)$f['rejected'] . '</td></tr>';
        foreach ($cands as $c) if (array_key_exists((int)$c['id'], $vt)) echo '<tr><th>' . h($c['name']) . '</th><td>' . $vt[(int)$c['id']] . '</td></tr>';
        echo '<tr><th>Sum check</th><td>' . (array_sum($vt) + (int)$f['rejected']) . ' (candidates + rejected)</td></tr><tr><th>Serial no.</th><td>' . h($f['serial_no']) . '</td></tr><tr><th>Centre code</th><td>' . h($f['centre_code']) . '</td></tr><tr><th>Came in by</th><td>' . h(TALLY_CHANNELS[$f['channel']] ?? $f['channel']) . '</td></tr><tr><th>Entered</th><td>' . h($f['entered_by']) . ' · ' . eat($f['entered_at']) . '</td></tr>';
        if ($f['note']) echo '<tr><th>Note</th><td>' . nl2br(h($f['note'])) . '</td></tr>';
        if ($f['accepted_by']) echo '<tr><th>Accepted</th><td>' . h($f['accepted_by']) . ': ' . h($f['accepted_note']) . '</td></tr>';
        echo '</tbody></table><h2>Second checks</h2>';
        if (!$checks) echo '<p class="muted">None yet.</p>';
        foreach ($checks as $c) { $t = json_decode($c['typed'], true) ?: []; echo '<p>' . ((int)$c['matches'] ? '<span class="tag ok">Matched</span>' : '<span class="tag bad">Differed</span>') . ' ' . h($c['by_user']) . ' · ' . eat($c['at']); if (!(int)$c['matches']) { echo '<br><small>Typed: total ' . h($t['total_cast'] ?? '') . ', rejected ' . h($t['rejected'] ?? ''); foreach ($cands as $cd) if (isset($t['votes'][$cd['id']])) echo ', ' . h($cd['name']) . ' ' . h($t['votes'][$cd['id']]); echo '</small>'; } echo '</p>'; }
    }
    $s = db()->prepare('SELECT * FROM tally_audit WHERE form_id = ? ORDER BY id'); $s->execute([$id]);
    echo '<h2>History</h2><table><tbody>'; foreach ($s->fetchAll() as $r) echo '<tr><td><small>' . eat($r['at']) . '</small></td><td>' . h($r['actor']) . '</td><td>' . h(str_replace('_', ' ', $r['action'])) . '</td></tr>'; echo '</tbody></table>';
    echo '</div><div>';
    if ($f['photo_file'] !== '') echo '<div class="card"><a href="?p=tally&photo=' . $id . '" target="_blank" rel="noopener"><img src="?p=tally&photo=' . $id . '" alt="Signed form" style="width:100%;border:1px solid var(--line)"></a><p class="small muted">Photo fingerprint (SHA-256): ' . h(substr($f['photo_sha'], 0, 16)) . '…</p></div>';
    if (!(int)$f['superseded']) {
        $form = fn($action, $inner, $btn, $cls = '') => '<form method="post" enctype="multipart/form-data" class="card stack"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '">' . $inner . '<button class="btn sm' . $cls . '">' . $btn . '</button></form>';
        if ($f['photo_file'] === '' && tally_can($me, 'photo')) echo $form('t_photo', '<h2>Attach the photo of the signed form</h2><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>', 'Attach photo');
        if ($f['status'] === 'Disputed' && $f['entered_by'] !== $user && tally_can($me, 'accept')) echo $form('t_accept', '<h2>Accept the figures</h2><p class="small muted">Only after comparing every figure with the photo. The warnings stay on record.</p><textarea name="note" rows="2" required placeholder="What you checked, e.g. turnout confirmed on the form and with the agent"></textarea>', 'Accept');
        if ($f['status'] !== 'Disputed' && tally_can($me, 'dispute')) echo $form('t_dispute', '<h2>Dispute this form</h2><textarea name="note" rows="2" required placeholder="Reason"></textarea>', 'Mark as disputed', ' ghost');
        if (tally_can($me, 'enter')) echo '<p><a class="btn sm ghost" href="?p=tally&v=enter&replaces=' . $id . '">Enter a corrected replacement</a></p>';
        if ($f['status'] === 'Disputed' && !tally_can($me, 'accept')) echo '<p class="small muted">Disputed forms are decided by Dr. Gideon or David.</p>';
    }
    echo '</div></div>';
}

function tally_view_missing(array $me): void {
    $form = t_form_type(); echo t_form_switch('missing', $form);
    $s = db()->prepare('SELECT ward, centre_code, stream, status FROM tally_forms WHERE form = ? AND superseded = 0'); $s->execute([$form]);
    $have = []; foreach ($s->fetchAll() as $r) $have[$r['ward'] . '|' . $r['centre_code'] . '|' . $r['stream']] = $r['status'];
    $missing = 0;
    foreach (CONSTITUENCIES as $c => $ws) foreach ($ws as $w) {
        $rows = '';
        foreach (tally_streams()[$w] ?? [] as $ctr) foreach ($ctr[2] as $i => $reg) if (!isset($have[$w . '|' . $ctr[1] . '|' . ($i + 1)])) { $missing++; $rows .= '<tr><td>' . h($ctr[0]) . '</td><td>' . ($i + 1) . '</td><td>' . (int)$reg . '</td><td><small>' . h($ctr[1]) . '</small></td></tr>'; }
        if ($rows) echo '<details><summary><b>' . h($w) . '</b>, ' . h($c) . ' · ' . substr_count($rows, '<tr>') . ' missing</summary><table><thead><tr><th>Polling station</th><th>Stream</th><th>Registered</th><th>Centre code</th></tr></thead><tbody>' . $rows . '</tbody></table></details>';
    }
    echo '<p><b>' . $missing . '</b> of ' . tally_stream_count() . ' streams have no form yet. Call the agent for each one.</p>';
}

function tally_view_audit(array $me): void {
    echo '<p class="muted">Every action in the tallying center, newest first. Nothing in the system can edit or delete this log.</p><table><thead><tr><th>Kenya time</th><th>Who</th><th>What</th><th>Form</th><th>Detail</th></tr></thead><tbody>';
    foreach (db()->query('SELECT * FROM tally_audit ORDER BY id DESC LIMIT 500')->fetchAll() as $r)
        echo '<tr><td><small>' . eat($r['at']) . '</small></td><td>' . h($r['actor']) . '</td><td>' . h(str_replace('_', ' ', $r['action'])) . '</td><td>' . ((int)$r['form_id'] ? '<a href="?p=tally&v=form&id=' . (int)$r['form_id'] . '">#' . (int)$r['form_id'] . '</a>' : '') . '</td><td><small style="word-break:break-all">' . h(mb_substr((string)$r['detail'], 0, 300)) . '</small></td></tr>';
    echo '</tbody></table>';
}

function tally_view_setup(array $me): void {
    $live = tally_meta('mode') === 'live';
    tally_staff_card();
    echo '<div class="cols even"><div>';
    foreach (TALLY_FORMS as $k => $t) {
        echo '<div class="card"><h2>Candidates · ' . h($t) . '</h2><p class="small muted">Add them in the order they appear on the IEBC form.</p><table><tbody>';
        foreach (tally_candidates($k) as $c) echo '<tr><td>' . (int)$c['sort'] . '</td><td>' . ((int)$c['ours'] ? '<b>' . h($c['name']) . '</b> <span class="pill on">ours</span>' : h($c['name'])) . '<br><small>' . h($c['party']) . '</small></td><td><form method="post"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="t_cand_del"><input type="hidden" name="cid" value="' . (int)$c['id'] . '"><button class="btn sm ghost">Remove</button></form></td></tr>';
        echo '</tbody></table><form method="post" class="row-edit" style="margin-top:10px"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="t_cand_add"><input type="hidden" name="form" value="' . $k . '"><input name="sort" type="number" min="0" value="' . (count(tally_candidates($k)) + 1) . '" style="max-width:70px;min-width:0" title="Order on the form"><input name="name" placeholder="Candidate name" required><input name="party" placeholder="Party"><label class="chk"><input type="checkbox" name="ours"> our candidate</label><button class="btn sm">Add</button></form></div>';
    }
    $close = tally_meta('polls_close');
    echo '</div><div><form method="post" class="card stack"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="t_settings"><h2>Warnings</h2><label>Warn when turnout is above (%)<input name="turnout_limit" type="number" min="50" max="100" step="0.5" value="' . h(tally_meta('turnout_limit', '90')) . '"></label><label>Polls close (Kenya time) <small>Forms entered before this are flagged</small><input name="polls_close" type="datetime-local" value="' . ($close ? gmdate('Y-m-d\TH:i', strtotime($close . ' UTC') + 3 * 3600) : '') . '"></label><button class="btn sm">Save</button></form>';
    if (!$live) {
        echo '<form method="post" class="card stack"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="t_clear"><h2>Clear rehearsal results</h2><p class="small muted">Removes all rehearsal forms so you can start another rehearsal. Candidates, settings and the audit log stay.</p><label>Type CLEAR<input name="confirm" autocomplete="off"></label><button class="btn sm ghost">Clear rehearsal results</button></form>';
        echo '<form method="post" class="card stack"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="t_golive"><h2>Start election day</h2><p class="small muted">Removes the rehearsal forms one last time and switches to election day. After this, results can never be cleared. Do this only on election morning.</p><label>Type LIVE<input name="confirm" autocomplete="off"></label><button class="btn sm">Start election day</button></form>';
    } else echo '<div class="card"><h2>Election day mode</h2><p>Results cannot be cleared. Wrong entries are corrected by entering a replacement, and both stay on record.</p></div>';
    echo '<div class="card help"><h2>Where the photos are kept</h2><p class="small">Photos of signed forms are saved outside the public website folder, so they cannot be opened by anyone on the internet. Only people signed in to the tallying center can see them. They are named by their fingerprint, so a photo can never be swapped without it showing.</p></div></div></div>';
}

/* ----- tallying-only staff accounts (managed by the two administrators in Setup) ----- */
function tally_staff_action(string $user, string $a): void {
    $id = (int)($_POST['sid'] ?? 0);
    if ($a === 't_staff_add') {
        $u = mb_strtolower(trim((string)($_POST['username'] ?? ''))); $pw = (string)($_POST['password'] ?? ''); $role = (string)($_POST['tally_role'] ?? '');
        $name = trim((string)($_POST['full_name'] ?? ''));
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $u)) t_go('&v=setup', 'Username: 3 to 60 letters, numbers, dot, dash or underscore.', true);
        if (!isset(TALLY_ROLES[$role])) t_go('&v=setup', 'Choose a role.', true);
        if ($name === '') t_go('&v=setup', 'Enter the person\'s full name.', true);
        if (strlen($pw) < 12) t_go('&v=setup', 'The first password must be at least 12 characters.', true);
        $s = db()->prepare('SELECT COUNT(*) FROM users WHERE LOWER(username) = ?'); $s->execute([$u]);
        $t = db()->prepare('SELECT COUNT(*) FROM tally_staff WHERE username = ?'); $t->execute([$u]);
        if ((int)$s->fetchColumn() || (int)$t->fetchColumn()) t_go('&v=setup', 'That username is already taken.', true);
        db()->prepare('INSERT INTO tally_staff (username, full_name, phone, pass_hash, tally_role, totp_secret, must_change, active, created_at, created_by, last_login) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$u, mb_substr($name, 0, 120), mb_substr(preg_replace('/[^0-9+ ]/', '', (string)($_POST['phone'] ?? '')), 0, 20), password_hash($pw, PASSWORD_DEFAULT), $role, '', 1, 1, now(), $user, '']);
        tally_audit($user, 'staff_added', 0, ['username' => $u, 'role' => $role]);
        t_go('&v=setup', 'Account created for ' . $name . '. Give them the username and first password in person. They set their own password and two-step sign-in the first time.');
    }
    $s = db()->prepare('SELECT * FROM tally_staff WHERE id = ?'); $s->execute([$id]); $st = $s->fetch();
    if (!$st) t_go('&v=setup', 'Account not found.', true);
    if ($a === 't_staff_toggle') {
        db()->prepare('UPDATE tally_staff SET active = ? WHERE id = ?')->execute([(int)$st['active'] ? 0 : 1, $id]);
        tally_audit($user, (int)$st['active'] ? 'staff_disabled' : 'staff_enabled', 0, ['username' => $st['username']]);
        t_go('&v=setup', (int)$st['active'] ? $st['username'] . ' can no longer sign in, and is signed out now.' : $st['username'] . ' can sign in again.');
    }
    if ($a === 't_staff_role' && isset(TALLY_ROLES[$_POST['tally_role'] ?? ''])) {
        db()->prepare('UPDATE tally_staff SET tally_role = ? WHERE id = ?')->execute([$_POST['tally_role'], $id]);
        tally_audit($user, 'staff_role', 0, ['username' => $st['username'], 'from' => $st['tally_role'], 'to' => $_POST['tally_role']]);
        t_go('&v=setup', 'Role changed.');
    }
    if ($a === 't_staff_reset') {
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 12) t_go('&v=setup', 'The new first password must be at least 12 characters.', true);
        db()->prepare("UPDATE tally_staff SET pass_hash = ?, totp_secret = '', must_change = 1 WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
        tally_audit($user, 'staff_reset', 0, ['username' => $st['username']]);
        t_go('&v=setup', 'Reset. ' . $st['username'] . ' signs in with the new first password and sets up two-step sign-in again on a new phone.');
    }
    t_go('&v=setup', 'Nothing was changed.', true);
}

function tally_staff_card(): void {
    $c = fn($a, $id, $inner, $btn) => '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="' . $a . '"><input type="hidden" name="sid" value="' . $id . '">' . $inner . '<button class="btn sm ghost">' . $btn . '</button></form>';
    echo '<div class="card"><h2>Center staff accounts</h2><p class="small muted">Clerks, verifiers and the legal desk sign in at <b>kucaparty.com/backoffice/tally.php</b>. They only ever see the tallying center: never volunteers, agents, voters, pledges, SMS or settings. Two-step sign-in is required for every one of them.</p>';
    echo '<ul class="small"><li><b>Data clerk:</b> types in forms and attaches photos.</li><li><b>Verifier:</b> re-types forms from the photo without seeing the first entry; can mark a form as disputed.</li><li><b>Legal desk:</b> sees results, every form, photo and the audit log; can mark a form as disputed and download results.</li><li>Only Dr. Gideon or David can accept a disputed form, change setup or manage these accounts.</li></ul>';
    $rows = db()->query('SELECT * FROM tally_staff ORDER BY active DESC, tally_role, full_name')->fetchAll();
    if ($rows) {
        echo '<table><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $opts = ''; foreach (TALLY_ROLES as $k => $t) $opts .= '<option value="' . $k . '"' . ($k === $r['tally_role'] ? ' selected' : '') . '>' . h($t) . '</option>';
            $state = !(int)$r['active'] ? '<span class="pill off">Disabled</span>' : ($r['totp_secret'] === '' ? '<span class="pill test">Not set up yet</span>' : '<span class="pill on">Active</span>');
            echo '<tr><td><b>' . h($r['full_name']) . '</b><br><small>' . h($r['username']) . ($r['phone'] ? ' · ' . h($r['phone']) : '') . '</small></td><td>' . $c('t_staff_role', (int)$r['id'], '<select name="tally_role">' . $opts . '</select>', 'Change') . '</td><td>' . $state . '</td><td><small>' . ($r['last_login'] ? eat($r['last_login']) : 'never') . '</small></td><td>'
                . $c('t_staff_toggle', (int)$r['id'], '', (int)$r['active'] ? 'Disable' : 'Enable')
                . '<details class="small"><summary>Reset password and two-step sign-in</summary>' . $c('t_staff_reset', (int)$r['id'], '<input type="password" name="password" placeholder="New first password (12+)" autocomplete="new-password" required>', 'Reset') . '</details></td></tr>';
        }
        echo '</tbody></table>';
    }
    $ro = ''; foreach (TALLY_ROLES as $k => $t) $ro .= '<option value="' . $k . '">' . h($t) . '</option>';
    echo '<h3>Add a person</h3><form method="post" class="two" autocomplete="off"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="t_staff_add"><label>Full name<input name="full_name" required></label><label>Phone<input name="phone" inputmode="tel"></label><label>Username<input name="username" required pattern="[A-Za-z0-9._-]{3,60}"></label><label>Role<select name="tally_role" required>' . $ro . '</select></label><label>First password (12+ characters; give it in person)<input type="password" name="password" required autocomplete="new-password"></label><div style="align-self:end"><button class="btn">Create account</button></div></form></div>';
}
