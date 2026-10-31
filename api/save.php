<?php
/* Receives sign-ups from the website forms and stores them in the back office database. */
declare(strict_types=1);
require __DIR__ . '/../backoffice/_lib.php';
require __DIR__ . '/../backoffice/_messaging.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $code, array $body) { http_response_code($code); echo json_encode($body); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'POST only']);
if (!cfg()) out(503, ['ok' => false, 'error' => 'not configured']);

/* Only accept posts from our own site. */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', $host)) out(403, ['ok' => false, 'error' => 'origin']);

$raw = file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode((string)$raw, true);
if (!is_array($in)) out(400, ['ok' => false, 'error' => 'bad request']);

/* Spam trap: real people never fill the hidden "website" field. Pretend success. */
if (!empty($in['website'])) out(200, ['ok' => true]);

function s(array $a, string $k, int $max = 120): string {
    $v = isset($a[$k]) && is_scalar($a[$k]) ? trim((string)$a[$k]) : '';
    $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
    return mb_substr($v, 0, $max);
}
function phone_ok(string $p): bool { $d = preg_replace('/\D/', '', $p); return strlen($d) >= 9 && strlen($d) <= 15; }

try {
    install_schema();
    if (too_many('save', 15, 3600)) out(429, ['ok' => false, 'error' => 'Too many submissions from this connection. Please try again later.']);
    if (empty($in['consent'])) out(400, ['ok' => false, 'error' => 'Please tick the consent box.']);

    $type = s($in, 'type', 20);
    $name = s($in, 'name'); $phone = s($in, 'phone', 30);
    if ($name === '' || !phone_ok($phone)) out(400, ['ok' => false, 'error' => 'Please enter your name and a valid phone number.']);
    $email = mb_strtolower(s($in, 'email', 160));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) out(400, ['ok' => false, 'error' => 'Please check your e-mail address, or leave it empty.']);

    if ($type === 'agent' || $type === 'volunteer') {
        $ward = s($in, 'ward', 40);
        $const = ward_constituency($ward);
        if ($const === '') out(400, ['ok' => false, 'error' => 'Please choose a valid ward.']);
    }

    if ($type === 'agent') {
        $station = s($in, 'station', 160); $stream = s($in, 'stream', 10);
        if ($station === '' || $stream === '') out(400, ['ok' => false, 'error' => 'Please choose your polling station and stream.']);
        $rec = ['name' => $name, 'phone' => $phone, 'constituency' => $const, 'ward' => $ward, 'station' => $station, 'station_code' => s($in, 'stationCode', 40), 'stream' => $stream, 'registered_here' => s($in, 'registeredHere', 40), 'experience' => s($in, 'experience', 40), 'training' => s($in, 'training', 20), 'email' => $email];
        db()->prepare('INSERT INTO agents (created_at, constituency, ward, station, station_code, stream, registered_here, name, phone, experience, training, email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([now(), $const, $ward, $station, $rec['station_code'], $stream, $rec['registered_here'], $name, $phone, $rec['experience'], $rec['training'], $email]);
    } elseif ($type === 'volunteer') {
        $roles = is_array($in['roles'] ?? null) ? implode(', ', array_map(fn($r) => mb_substr(trim((string)$r), 0, 40), array_slice($in['roles'], 0, 12))) : '';
        $rec = ['name' => $name, 'phone' => $phone, 'constituency' => $const, 'ward' => $ward, 'station' => s($in, 'station', 160), 'stream' => s($in, 'stream', 10), 'village' => s($in, 'village'), 'age' => s($in, 'age', 20), 'roles' => $roles, 'message' => s($in, 'message', 1000), 'email' => $email];
        db()->prepare('INSERT INTO volunteers (created_at, constituency, ward, station, stream, village, age, roles, message, name, phone, email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([now(), $const, $ward, $rec['station'], $rec['stream'], $rec['village'], $rec['age'], $roles, $rec['message'], $name, $phone, $email]);
    } elseif ($type === 'pledge') {
        $amt = (int)preg_replace('/\D/', '', (string)($in['amountKsh'] ?? ''));
        if ($amt < 1 || $amt > 100000000) out(400, ['ok' => false, 'error' => 'Please choose an amount.']);
        $rec = ['name' => $name, 'phone' => $phone, 'amount_ksh' => $amt, 'method' => s($in, 'method', 60), 'email' => $email];
        db()->prepare('INSERT INTO pledges (created_at, name, phone, amount_ksh, method, email) VALUES (?,?,?,?,?,?)')
            ->execute([now(), $name, $phone, $amt, $rec['method'], $email]);
    } else {
        out(400, ['ok' => false, 'error' => 'unknown form']);
    }
    $id = (int)db()->lastInsertId();
    record_hit('save');
} catch (Throwable $e) {
    error_log('save.php: ' . $e->getMessage());
    out(500, ['ok' => false, 'error' => 'server']);
}

/* Answer the visitor first, then send the confirmation SMS and the team e-mail. */
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); flush(); }
ignore_user_abort(true);
try { notify_signup($type, $id, $rec); } catch (Throwable $e) { error_log('save.php notify: ' . $e->getMessage()); }
