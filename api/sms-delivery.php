<?php
/*
 * HostPinnacle delivery reports. Registered from the back office (SMS > Register delivery webhook) as
 *   https://kucaparty.com/api/sms-delivery.php?token=<callback_token from backoffice/config.php>
 * Every call is kept in webhook_log so the team can see exactly what HostPinnacle sent,
 * then the matching SMS is marked delivered, failed, etc.
 */
declare(strict_types=1);
require __DIR__ . '/../backoffice/_lib.php';
require __DIR__ . '/../backoffice/_messaging.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $body) { http_response_code($code); echo json_encode($body); exit; }

if (!cfg()) out(503, ['ok' => false, 'error' => 'not configured']);
$token = (string)(sms_cfg()['callback_token'] ?? '');
if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) out(403, ['ok' => false, 'error' => 'forbidden']);

$raw = (string)file_get_contents('php://input', false, null, 0, 100000);
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST ?: array_diff_key($_GET, ['token' => 1]);

try {
    install_schema();
    db()->prepare('INSERT INTO webhook_log (created_at, payload, ip_hash) VALUES (?,?,?)')
        ->execute([now(), mb_substr($raw !== '' ? $raw : (string)json_encode($in), 0, 10000), ip_hash()]);

    // One report, a list of reports, or a list under "data".
    $reports = isset($in[0]) && is_array($in[0]) ? $in : (isset($in['data'][0]) && is_array($in['data'][0]) ? $in['data'] : [$in]);
    $upd = db()->prepare("UPDATE sms_log SET status = ?, error = COALESCE(NULLIF(?, ''), error), updated_at = ? WHERE provider_id = ?");
    $n = 0;
    foreach ($reports as $r) {
        if (!is_array($r)) continue;
        $pid = (string)(hp_find($r, ['transactionid', 'msgid', 'messageid', 'uuid', 'id']) ?? '');
        $st = (string)(hp_find($r, ['dlrstatus', 'deliverystatus', 'status', 'dlr']) ?? '');
        if ($pid === '' || $st === '') continue;
        $upd->execute([mb_strtolower(mb_substr($st, 0, 40)), mb_substr((string)(hp_find($r, ['reason', 'description', 'errorcode', 'error']) ?? ''), 0, 255), now(), $pid]);
        $n += $upd->rowCount();
    }
    out(200, ['ok' => true, 'updated' => $n]);
} catch (Throwable $e) {
    error_log('sms-delivery.php: ' . $e->getMessage());
    out(500, ['ok' => false, 'error' => 'server']);
}
