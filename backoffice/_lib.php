<?php
/* Shared code for the Nyakundi 2027 back office and the sign-up API. */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_lib.php') { http_response_code(404); exit; }

const CONSTITUENCIES = [
    'Kitutu Masaba'   => ['Rigoma', 'Gachuba', 'Kemera', 'Magombo', 'Manga', 'Gesima'],
    'West Mugirango'  => ['Nyamaiya', 'Bogichora', 'Bosamaro', 'Bonyamatuta', 'Township'],
    'North Mugirango' => ['Bomwagamo', 'Bokeira', 'Magwagwa', 'Ekerenyo', 'Itibo'],
    'Borabu'          => ['Mekenene', 'Esise', 'Nyansiongo', 'Kiabonyoru'],
];
const AGENT_STATUSES = ['New', 'Called', 'Confirmed', 'Trained', 'Accredited', 'Not available'];
const VOL_STATUSES   = ['New', 'Called', 'Active', 'Not available'];
const PLEDGE_STATUSES = ['New', 'Called', 'Paid', 'Declined'];
/* Only two people may use the back office (Dr. Gideon and David). Accounts beyond the first two can never sign in. */
const MAX_ACCOUNTS = 2;
const VOTER_STATUSES = ['New', 'Called', 'Helped to register', 'Verified on register', 'Not available'];

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        if (!is_file($f)) { $c = []; } else { $c = require $f; }
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg();
    if (!$c) throw new RuntimeException('config.php is missing');
    if (!empty($c['dsn'])) {               // used for local testing only
        $pdo = new PDO($c['dsn']);
    } else {
        $pdo = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function is_mysql(): bool { return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }

function install_schema(): void {
    $pk = is_mysql() ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = is_mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $t = [
        "CREATE TABLE IF NOT EXISTS agents (id $pk, created_at VARCHAR(19) NOT NULL, constituency VARCHAR(40) NOT NULL, ward VARCHAR(40) NOT NULL, station VARCHAR(160) NOT NULL, station_code VARCHAR(40) NOT NULL DEFAULT '', stream VARCHAR(10) NOT NULL, registered_here VARCHAR(40) NOT NULL DEFAULT '', name VARCHAR(120) NOT NULL, phone VARCHAR(30) NOT NULL, experience VARCHAR(40) NOT NULL DEFAULT '', training VARCHAR(20) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'New', notes TEXT, updated_at VARCHAR(19), updated_by VARCHAR(60))$tail",
        "CREATE TABLE IF NOT EXISTS volunteers (id $pk, created_at VARCHAR(19) NOT NULL, constituency VARCHAR(40) NOT NULL, ward VARCHAR(40) NOT NULL, station VARCHAR(160) NOT NULL DEFAULT '', stream VARCHAR(10) NOT NULL DEFAULT '', village VARCHAR(120) NOT NULL DEFAULT '', age VARCHAR(20) NOT NULL DEFAULT '', roles VARCHAR(400) NOT NULL DEFAULT '', message TEXT, name VARCHAR(120) NOT NULL, phone VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'New', notes TEXT, updated_at VARCHAR(19), updated_by VARCHAR(60))$tail",
        "CREATE TABLE IF NOT EXISTS pledges (id $pk, created_at VARCHAR(19) NOT NULL, name VARCHAR(120) NOT NULL, phone VARCHAR(30) NOT NULL, amount_ksh INT NOT NULL, method VARCHAR(60) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'New', notes TEXT, updated_at VARCHAR(19), updated_by VARCHAR(60))$tail",
        "CREATE TABLE IF NOT EXISTS voters (id $pk, created_at VARCHAR(19) NOT NULL, constituency VARCHAR(40) NOT NULL, ward VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, phone VARCHAR(30) NOT NULL, email VARCHAR(160) NOT NULL DEFAULT '', reg_status VARCHAR(60) NOT NULL DEFAULT '', status VARCHAR(30) NOT NULL DEFAULT 'New', notes TEXT, updated_at VARCHAR(19), updated_by VARCHAR(60))$tail",
        "CREATE TABLE IF NOT EXISTS users (id $pk, username VARCHAR(60) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'coordinator', constituency VARCHAR(40) NOT NULL DEFAULT '', created_at VARCHAR(19) NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS hits (id $pk, kind VARCHAR(20) NOT NULL, ip_hash VARCHAR(64) NOT NULL, at INT NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS sms_log (id $pk, created_at VARCHAR(19) NOT NULL, phone VARCHAR(20) NOT NULL, message TEXT NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'manual', status VARCHAR(40) NOT NULL DEFAULT 'queued', provider_id VARCHAR(80), error VARCHAR(255), record_type VARCHAR(20) NOT NULL DEFAULT '', record_id INT NOT NULL DEFAULT 0, sent_by VARCHAR(60) NOT NULL DEFAULT '', updated_at VARCHAR(19))$tail",
        "CREATE TABLE IF NOT EXISTS email_log (id $pk, created_at VARCHAR(19) NOT NULL, to_addr VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, status VARCHAR(20) NOT NULL, error VARCHAR(500), record_type VARCHAR(20) NOT NULL DEFAULT '', record_id INT NOT NULL DEFAULT 0)$tail",
        "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(60) NOT NULL PRIMARY KEY, v TEXT NOT NULL, updated_at VARCHAR(19), updated_by VARCHAR(60))$tail",
        "CREATE TABLE IF NOT EXISTS survey (id $pk, created_at VARCHAR(19) NOT NULL, src VARCHAR(40) NOT NULL DEFAULT 'direct', constituency VARCHAR(40) NOT NULL, ward VARCHAR(40) NOT NULL, age VARCHAR(10) NOT NULL, gender VARCHAR(20) NOT NULL, registered VARCHAR(30) NOT NULL, issue VARCHAR(40) NOT NULL, vote VARCHAR(60) NOT NULL, heard VARCHAR(40) NOT NULL, volunteer TINYINT NOT NULL DEFAULT 0, phone_key VARCHAR(64) NOT NULL UNIQUE)$tail",
        "CREATE TABLE IF NOT EXISTS webhook_log (id $pk, created_at VARCHAR(19) NOT NULL, payload TEXT NOT NULL, ip_hash VARCHAR(64) NOT NULL DEFAULT '')$tail",
    ];
    foreach ($t as $q) db()->exec($q);
    foreach (['agents', 'volunteers', 'pledges'] as $tb) add_column($tb, 'email', "VARCHAR(160) NOT NULL DEFAULT ''");
    add_column('users', 'totp_secret', "VARCHAR(64) NOT NULL DEFAULT ''");
    add_column('email_log', 'kind', "VARCHAR(20) NOT NULL DEFAULT ''");
}

/* Adds a column to a table created by an older version, once. */
function add_column(string $table, string $col, string $def): void {
    try { db()->query("SELECT $col FROM $table LIMIT 1"); }
    catch (Throwable $e) { db()->exec("ALTER TABLE $table ADD COLUMN $col $def"); }
}

function now(): string { return gmdate('Y-m-d H:i:s'); }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ip_hash(): string { return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (cfg()['secret'] ?? '')); }

/* Simple rate limit: at most $max events of $kind per IP in $window seconds. */
function too_many(string $kind, int $max, int $window, ?string $key = null): bool {
    $t = time();
    db()->prepare('DELETE FROM hits WHERE at < ?')->execute([$t - 86400]);
    $s = db()->prepare('SELECT COUNT(*) FROM hits WHERE kind = ? AND ip_hash = ? AND at > ?');
    $s->execute([$kind, $key ?? ip_hash(), $t - $window]);
    return (int)$s->fetchColumn() >= $max;
}
function record_hit(string $kind, ?string $key = null): void {
    db()->prepare('INSERT INTO hits (kind, ip_hash, at) VALUES (?, ?, ?)')->execute([$kind, $key ?? ip_hash(), time()]);
}

/* Polling stations from the IEBC list that ships with the site: ward => [[name, code, [voters per stream]], ...] */
function stations(): array {
    static $s = null;
    if ($s !== null) return $s;
    $f = dirname(__DIR__) . '/nyamira-polling-stations.json';
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $s = [];
    foreach (($j['wards'] ?? []) as $ward => $w) $s[$ward] = $w['centres'] ?? [];
    return $s;
}
function ward_constituency(string $ward): string {
    foreach (CONSTITUENCIES as $c => $ws) if (in_array($ward, $ws, true)) return $c;
    return '';
}

/* ---------- sessions, login, CSRF ---------- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('nyk_bo');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/backoffice/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (!empty($_SESSION['uid']) && (time() - ($_SESSION['seen'] ?? 0)) > 4 * 3600) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['seen'] = time();
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_ok(): bool { return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']); }
function allowed_ids(): array {
    return array_map('intval', db()->query('SELECT id FROM users ORDER BY id LIMIT ' . MAX_ACCOUNTS)->fetchAll(PDO::FETCH_COLUMN));
}
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    if (!in_array((int)$_SESSION['uid'], allowed_ids(), true)) return null;
    $s = db()->prepare('SELECT id, username, role, constituency, totp_secret FROM users WHERE id = ?');
    $s->execute([$_SESSION['uid']]);
    $u = $s->fetch() ?: null;
    if ($u) $u['role'] = 'admin';   // both permitted people are administrators
    return $u;
}

/* ---------- two-step sign-in (authenticator app codes, RFC 6238) ---------- */
function b32_encode(string $bin): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $ch) $out .= $a[bindec(str_pad($ch, 5, '0'))];
    return $out;
}
function b32_decode(string $b32): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $by) if (strlen($by) === 8) $out .= chr(bindec($by));
    return $out;
}
function totp_code(string $secret, int $step): string {
    $h = hash_hmac('sha1', pack('N*', 0, $step), b32_decode($secret), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}
function totp_ok(string $secret, string $code): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secret === '') return false;
    $t = intdiv(time(), 30);
    foreach ([-1, 0, 1] as $d) if (hash_equals(totp_code($secret, $t + $d), $code)) return true;
    return false;
}
function security_headers(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store');
}
