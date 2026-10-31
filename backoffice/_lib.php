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
        "CREATE TABLE IF NOT EXISTS users (id $pk, username VARCHAR(60) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'coordinator', constituency VARCHAR(40) NOT NULL DEFAULT '', created_at VARCHAR(19) NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS hits (id $pk, kind VARCHAR(20) NOT NULL, ip_hash VARCHAR(64) NOT NULL, at INT NOT NULL)$tail",
    ];
    foreach ($t as $q) db()->exec($q);
}

function now(): string { return gmdate('Y-m-d H:i:s'); }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ip_hash(): string { return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (cfg()['secret'] ?? '')); }

/* Simple rate limit: at most $max events of $kind per IP in $window seconds. */
function too_many(string $kind, int $max, int $window): bool {
    $t = time();
    db()->prepare('DELETE FROM hits WHERE at < ?')->execute([$t - 86400]);
    $s = db()->prepare('SELECT COUNT(*) FROM hits WHERE kind = ? AND ip_hash = ? AND at > ?');
    $s->execute([$kind, ip_hash(), $t - $window]);
    return (int)$s->fetchColumn() >= $max;
}
function record_hit(string $kind): void {
    db()->prepare('INSERT INTO hits (kind, ip_hash, at) VALUES (?, ?, ?)')->execute([$kind, ip_hash(), time()]);
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
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $s = db()->prepare('SELECT id, username, role, constituency FROM users WHERE id = ?');
    $s->execute([$_SESSION['uid']]);
    return $s->fetch() ?: null;
}
function security_headers(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store');
}
