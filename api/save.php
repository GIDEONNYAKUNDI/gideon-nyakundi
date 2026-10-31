<?php
/* Receives sign-ups from the website forms and stores them in the back office database. */
declare(strict_types=1);
require __DIR__ . '/../backoffice/_lib.php';

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

    if ($type === 'agent' || $type === 'volunteer') {
        $ward = s($in, 'ward', 40);
        $const = ward_constituency($ward);
        if ($const === '') out(400, ['ok' => false, 'error' => 'Please choose a valid ward.']);
    }

    if ($type === 'agent') {
        $station = s($in, 'station', 160); $stream = s($in, 'stream', 10);
        if ($station === '' || $stream === '') out(400, ['ok' => false, 'error' => 'Please choose your polling station and stream.']);
        db()->prepare('INSERT INTO agents (created_at, constituency, ward, station, station_code, stream, registered_here, name, phone, experience, training) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([now(), $const, $ward, $station, s($in, 'stationCode', 40), $stream, s($in, 'registeredHere', 40), $name, $phone, s($in, 'experience', 40), s($in, 'training', 20)]);
    } elseif ($type === 'volunteer') {
        $roles = is_array($in['roles'] ?? null) ? implode(', ', array_map(fn($r) => mb_substr(trim((string)$r), 0, 40), array_slice($in['roles'], 0, 12))) : '';
        db()->prepare('INSERT INTO volunteers (created_at, constituency, ward, station, stream, village, age, roles, message, name, phone) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([now(), $const, $ward, s($in, 'station', 160), s($in, 'stream', 10), s($in, 'village'), s($in, 'age', 20), $roles, s($in, 'message', 1000), $name, $phone]);
    } elseif ($type === 'pledge') {
        $amt = (int)preg_replace('/\D/', '', (string)($in['amountKsh'] ?? ''));
        if ($amt < 1 || $amt > 100000000) out(400, ['ok' => false, 'error' => 'Please choose an amount.']);
        db()->prepare('INSERT INTO pledges (created_at, name, phone, amount_ksh, method) VALUES (?,?,?,?,?)')
            ->execute([now(), $name, $phone, $amt, s($in, 'method', 60)]);
    } else {
        out(400, ['ok' => false, 'error' => 'unknown form']);
    }
    record_hit('save');
    out(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('save.php: ' . $e->getMessage());
    out(500, ['ok' => false, 'error' => 'server']);
}
