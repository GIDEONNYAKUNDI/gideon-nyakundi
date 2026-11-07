<?php
/* Tallying center: database, automatic checks, verification rules and audit log.
   Used by tally.php. Results are counted per STREAM (each stream has its own signed form). */
declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_tally.php') { http_response_code(404); exit; }

const TALLY_FORMS = ['GOV' => 'Form 37A · Governor', 'PRES' => 'Form 34A · President'];
/* Tallying-only staff. They sign in at tally.php and can never reach the volunteers, agents, voters or pledges lists. */
const TALLY_ROLES = ['clerk' => 'Data clerk', 'verifier' => 'Verifier', 'legal' => 'Legal desk'];
const TALLY_CAN = [
    'enter'   => ['clerk'],
    'check'   => ['verifier'],
    'photo'   => ['clerk', 'verifier'],
    'dispute' => ['verifier', 'legal'],
    'accept'  => [],
    'results' => ['legal'],
    'audit'   => ['legal'],
    'forms'   => ['clerk', 'verifier', 'legal'],
    'missing' => ['clerk', 'verifier', 'legal'],
    'setup'   => [],
];
/* The two back office administrators (Dr. Gideon and David) can do everything; staff only what their role allows. */
function tally_can(array $me, string $what): bool {
    if (($me['role'] ?? '') === 'admin') return true;
    return ($me['role'] ?? '') === 'staff' && in_array($me['tally_role'] ?? '', TALLY_CAN[$what] ?? [], true);
}
const TALLY_CHANNELS = ['clerk' => 'Typed by a clerk', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'app' => 'Agent app'];
/* Missing: no form yet for the stream. Received: typed in once, waiting for a second person's blind check.
   Pending: figures confirmed by two people but no photo of the signed form yet (SMS or WhatsApp entry).
   Verified: confirmed by two people against the photo of the signed form. Disputed: a check failed; the director decides. */
const TALLY_STATUSES = ['Missing', 'Received', 'Pending', 'Verified', 'Disputed'];
const FLAG_TEXT = [
    'over_registered' => 'More votes cast than registered voters',
    'sum_mismatch'    => 'Candidate votes plus rejected votes do not equal total cast',
    'high_turnout'    => 'Turnout unusually high',
    'duplicate_stream'=> 'Another form already received for this stream',
    'duplicate_photo' => 'The same photo was used for another stream',
    'duplicate_serial'=> 'This form serial number is already used on another stream',
    'too_early'       => 'Submitted before polls closed',
    'verifier_differs'=> 'A verifier read different figures from the photo',
    'manual'          => 'Marked as disputed by the center',
];

function tally_schema(): void {
    $pk = is_mysql() ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = is_mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    foreach ([
        "CREATE TABLE IF NOT EXISTS tally_staff (id $pk, username VARCHAR(60) NOT NULL UNIQUE, full_name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(20) NOT NULL DEFAULT '', pass_hash VARCHAR(255) NOT NULL, tally_role VARCHAR(20) NOT NULL, totp_secret VARCHAR(64) NOT NULL DEFAULT '', must_change INT NOT NULL DEFAULT 1, active INT NOT NULL DEFAULT 1, created_at VARCHAR(19) NOT NULL, created_by VARCHAR(60) NOT NULL DEFAULT '', last_login VARCHAR(19) NOT NULL DEFAULT '')$tail",
        "CREATE TABLE IF NOT EXISTS tally_candidates (id $pk, form VARCHAR(8) NOT NULL, name VARCHAR(120) NOT NULL, party VARCHAR(120) NOT NULL DEFAULT '', sort INT NOT NULL DEFAULT 0, ours INT NOT NULL DEFAULT 0)$tail",
        "CREATE TABLE IF NOT EXISTS tally_forms (id $pk, form VARCHAR(8) NOT NULL, constituency VARCHAR(40) NOT NULL, ward VARCHAR(40) NOT NULL, station VARCHAR(160) NOT NULL, centre_code VARCHAR(40) NOT NULL DEFAULT '', stream INT NOT NULL, registered INT NOT NULL DEFAULT 0, serial_no VARCHAR(40) NOT NULL DEFAULT '', total_cast INT NOT NULL, rejected INT NOT NULL, channel VARCHAR(12) NOT NULL, entered_by VARCHAR(80) NOT NULL, entered_at VARCHAR(19) NOT NULL, gps_lat VARCHAR(20) NOT NULL DEFAULT '', gps_lng VARCHAR(20) NOT NULL DEFAULT '', photo_file VARCHAR(120) NOT NULL DEFAULT '', photo_sha VARCHAR(64) NOT NULL DEFAULT '', status VARCHAR(12) NOT NULL DEFAULT 'Received', flags VARCHAR(400) NOT NULL DEFAULT '', accepted_by VARCHAR(80) NOT NULL DEFAULT '', accepted_note TEXT, superseded INT NOT NULL DEFAULT 0, note TEXT)$tail",
        "CREATE TABLE IF NOT EXISTS tally_votes (form_id INT NOT NULL, candidate_id INT NOT NULL, votes INT NOT NULL, PRIMARY KEY (form_id, candidate_id))$tail",
        "CREATE TABLE IF NOT EXISTS tally_checks (id $pk, form_id INT NOT NULL, by_user VARCHAR(80) NOT NULL, at VARCHAR(19) NOT NULL, typed TEXT NOT NULL, matches INT NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS tally_audit (id $pk, at VARCHAR(19) NOT NULL, actor VARCHAR(80) NOT NULL, action VARCHAR(40) NOT NULL, form_id INT NOT NULL DEFAULT 0, detail TEXT)$tail",
        "CREATE TABLE IF NOT EXISTS tally_meta (k VARCHAR(40) NOT NULL PRIMARY KEY, v TEXT)$tail",
    ] as $q) db()->exec($q);
}

/* The audit log is only ever added to; nothing in the system edits or deletes it. */
function tally_audit(string $actor, string $action, int $formId = 0, array $detail = []): void {
    db()->prepare('INSERT INTO tally_audit (at, actor, action, form_id, detail) VALUES (?,?,?,?,?)')
        ->execute([now(), $actor, $action, $formId, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : '']);
}

function tally_meta(string $k, string $default = ''): string {
    $s = db()->prepare('SELECT v FROM tally_meta WHERE k = ?'); $s->execute([$k]);
    $v = $s->fetchColumn(); return $v === false ? $default : (string)$v;
}
function tally_set_meta(string $k, string $v): void {
    db()->prepare('DELETE FROM tally_meta WHERE k = ?')->execute([$k]);
    db()->prepare('INSERT INTO tally_meta (k, v) VALUES (?,?)')->execute([$k, $v]);
}

/* Every stream in the IEBC list: [ward => [ [centre name, full centre code, [registered per stream]], ...] ] */
function tally_streams(): array {
    static $s = null;
    if ($s !== null) return $s;
    $j = json_decode((string)@file_get_contents(dirname(__DIR__) . '/nyamira-polling-stations.json'), true) ?: [];
    $s = [];
    foreach (($j['wards'] ?? []) as $ward => $w) foreach (($w['centres'] ?? []) as $c) $s[$ward][] = [$c[0], ($w['code'] ?? '') . $c[1], $c[2]];
    return $s;
}
function tally_stream_count(string $scopeConst = '', string $scopeWard = ''): int {
    $n = 0;
    foreach (CONSTITUENCIES as $c => $ws) { if ($scopeConst && $c !== $scopeConst) continue; foreach ($ws as $w) { if ($scopeWard && $w !== $scopeWard) continue; foreach (tally_streams()[$w] ?? [] as $ctr) $n += count($ctr[2]); } }
    return $n;
}
function tally_find_centre(string $ward, string $centreCode): ?array {
    foreach (tally_streams()[$ward] ?? [] as $c) if ($c[1] === $centreCode) return $c;
    return null;
}

function tally_candidates(string $form): array {
    $s = db()->prepare('SELECT * FROM tally_candidates WHERE form = ? ORDER BY sort, id'); $s->execute([$form]);
    return $s->fetchAll();
}
function tally_votes(int $formId): array {
    $s = db()->prepare('SELECT candidate_id, votes FROM tally_votes WHERE form_id = ?'); $s->execute([$formId]);
    $o = []; foreach ($s->fetchAll() as $r) $o[(int)$r['candidate_id']] = (int)$r['votes']; return $o;
}
function tally_form(int $id): ?array {
    $s = db()->prepare('SELECT * FROM tally_forms WHERE id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
}

/* Run the automatic checks and set the status from the checks and verifications. */
function tally_recheck(int $id): array {
    $f = tally_form($id); if (!$f) return [];
    $flags = [];
    $votes = tally_votes($id);
    if ((int)$f['registered'] > 0 && (int)$f['total_cast'] > (int)$f['registered']) $flags[] = 'over_registered';
    if (array_sum($votes) + (int)$f['rejected'] !== (int)$f['total_cast']) $flags[] = 'sum_mismatch';
    $limit = (float)tally_meta('turnout_limit', '90');
    if ((int)$f['registered'] > 0 && 100 * (int)$f['total_cast'] / (int)$f['registered'] > $limit) $flags[] = 'high_turnout';
    if (!(int)$f['superseded']) {
        $s = db()->prepare('SELECT COUNT(*) FROM tally_forms WHERE form = ? AND ward = ? AND centre_code = ? AND stream = ? AND superseded = 0 AND id <> ?');
        $s->execute([$f['form'], $f['ward'], $f['centre_code'], $f['stream'], $id]);
        if ((int)$s->fetchColumn() > 0) $flags[] = 'duplicate_stream';
    }
    if ($f['photo_sha'] !== '') {
        $s = db()->prepare('SELECT COUNT(*) FROM tally_forms WHERE photo_sha = ? AND id <> ? AND NOT (ward = ? AND centre_code = ? AND stream = ?)');
        $s->execute([$f['photo_sha'], $id, $f['ward'], $f['centre_code'], $f['stream']]);
        if ((int)$s->fetchColumn() > 0) $flags[] = 'duplicate_photo';
    }
    if (trim($f['serial_no']) !== '') {
        $s = db()->prepare('SELECT COUNT(*) FROM tally_forms WHERE serial_no = ? AND form = ? AND id <> ? AND NOT (ward = ? AND centre_code = ? AND stream = ?)');
        $s->execute([$f['serial_no'], $f['form'], $id, $f['ward'], $f['centre_code'], $f['stream']]);
        if ((int)$s->fetchColumn() > 0) $flags[] = 'duplicate_serial';
    }
    $close = tally_meta('polls_close');
    if ($close !== '' && $f['entered_at'] < $close) $flags[] = 'too_early';
    $s = db()->prepare('SELECT matches FROM tally_checks WHERE form_id = ? ORDER BY id'); $s->execute([$id]);
    $checks = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    if (in_array(0, $checks, true)) $flags[] = 'verifier_differs';
    foreach (array_filter(explode(',', $f['flags'])) as $old) if ($old === 'manual') $flags[] = 'manual';
    $flags = array_values(array_unique($flags));
    $good = count(array_filter($checks));
    if ((int)$f['superseded']) $status = 'Superseded';
    elseif ($flags && $f['accepted_by'] === '') $status = 'Disputed';
    elseif ($good >= 1 || $f['accepted_by'] !== '') $status = $f['photo_file'] !== '' ? 'Verified' : 'Pending';
    else $status = 'Received';
    db()->prepare('UPDATE tally_forms SET flags = ?, status = ? WHERE id = ?')->execute([implode(',', $flags), $status, $id]);
    return $flags;
}
/* Re-run checks on every form of the same stream (a new or superseded form changes the others' duplicate flag). */
function tally_recheck_stream(array $f): void {
    $s = db()->prepare('SELECT id FROM tally_forms WHERE form = ? AND ward = ? AND centre_code = ? AND stream = ?');
    $s->execute([$f['form'], $f['ward'], $f['centre_code'], $f['stream']]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $i) tally_recheck((int)$i);
}

/* Photos of signed forms live outside the public website folder and are served only to signed-in center users. */
function tally_photo_dir(): string {
    $d = cfg()['tally_photo_dir'] ?? (dirname(__DIR__, 2) . '/tally_private/photos');
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
function tally_store_photo(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['', '', ''];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return ['', '', 'The photo did not upload. Try again.'];
    if ($file['size'] > 15 * 1024 * 1024) return ['', '', 'The photo is larger than 15 MB.'];
    $info = @getimagesize($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? '';
    if ($ext === '') return ['', '', 'The photo must be a JPG, PNG or WebP picture.'];
    $sha = hash_file('sha256', $file['tmp_name']);
    $name = $sha . '.' . $ext;
    $dest = tally_photo_dir() . '/' . $name;
    if (!is_file($dest) && !move_uploaded_file($file['tmp_name'], $dest)) return ['', '', 'The photo could not be saved on the server.'];
    @chmod($dest, 0600);
    return [$name, $sha, ''];
}
