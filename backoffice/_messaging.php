<?php
/*
 * SMS through HostPinnacle and e-mail through SMTP, for the back office and the sign-up API.
 * Every SMS goes to sms_log and every e-mail to email_log, so the team can see what was sent and whether it arrived.
 */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_messaging.php') { http_response_code(404); exit; }

const HP_BASE = 'https://smsportal.hostpinnacle.co.ke/SMSApi';

function sms_cfg(): array { return (cfg()['sms'] ?? []) + ['enabled' => false, 'test_mode' => true, 'templates' => [], 'alert_numbers' => []]; }
function smtp_cfg(): array { return (cfg()['smtp'] ?? []) + ['enabled' => false, 'notify' => []]; }

/* Kenyan numbers to 2547XXXXXXXX / 2541XXXXXXXX (the format HostPinnacle wants). '' if not a valid number. */
function kenya_phone(string $raw): string {
    $d = preg_replace('/\D/', '', $raw) ?? '';
    if (strlen($d) === 10 && $d[0] === '0') $d = '254' . substr($d, 1);
    elseif (strlen($d) === 9 && ($d[0] === '7' || $d[0] === '1')) $d = '254' . $d;
    if (preg_match('/^254[17]\d{8}$/', $d)) return $d;
    if (strpos(trim($raw), '+') === 0 && strlen($d) >= 10 && strlen($d) <= 15) return $d;   // diaspora numbers
    return '';
}

/* ---------- HostPinnacle ---------- */

function hp_request(string $method, string $path, array $fields = []): array {
    $c = sms_cfg();
    $fields += ['userid' => $c['userid'] ?? '', 'password' => $c['password'] ?? '', 'output' => 'json'];
    $url = rtrim($c['base_url'] ?? HP_BASE, '/') . $path;
    $ch = curl_init();
    if ($method === 'GET') $url .= '?' . http_build_query($fields);
    else { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['apikey: ' . ($c['api_key'] ?? ''), 'Accept: application/json', 'Cache-Control: no-cache']]);
    $body = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'error' => 'Network error: ' . $err];
    $j = json_decode((string)$body, true);
    if ($code >= 400 || !is_array($j)) return ['ok' => false, 'error' => 'HTTP ' . $code . ': ' . mb_substr(trim(strip_tags((string)$body)), 0, 200)];
    // HostPinnacle reports errors inside a 200 reply: {"status":"error","reason":"..."}.
    $st = strtolower((string)($j['status'] ?? $j['response']['status'] ?? ''));
    $sc = (string)($j['statusCode'] ?? $j['response']['statusCode'] ?? '');
    if ($st !== 'success' && $sc !== '200') return ['ok' => false, 'error' => (string)($j['reason'] ?? $j['response']['reason'] ?? $j['message'] ?? mb_substr((string)$body, 0, 200)), 'data' => $j];
    return ['ok' => true, 'data' => $j];
}

/* First value for any of $keys anywhere in a nested reply (HostPinnacle's field names are not documented). */
function hp_find(array $data, array $keys) {
    foreach ($data as $k => $v) if (is_string($k) && in_array(strtolower($k), $keys, true) && is_scalar($v) && $v !== '') return $v;
    foreach ($data as $v) if (is_array($v) && ($f = hp_find($v, $keys)) !== null) return $f;
    return null;
}

/*
 * Sends $message to each number separately, so every SMS gets its own delivery status.
 * $ref ties the SMS to a sign-up, e.g. ['agents', 12]. Returns ['sent' => n, 'failed' => n, 'error' => ?string].
 */
function sms_send(array $phones, string $message, string $kind, string $by = 'website', array $ref = ['', 0]): array {
    $c = sms_cfg();
    $phones = array_values(array_unique(array_filter(array_map('kenya_phone', $phones))));
    $res = ['sent' => 0, 'failed' => 0, 'error' => null];
    if (!$phones) { $res['error'] = 'No valid phone numbers.'; return $res; }
    if (count($phones) > 20) @set_time_limit(0);
    $ins = db()->prepare('INSERT INTO sms_log (created_at, phone, message, kind, status, record_type, record_id, sent_by) VALUES (?,?,?,?,?,?,?,?)');
    $upd = db()->prepare('UPDATE sms_log SET status = ?, provider_id = ?, error = ?, updated_at = ? WHERE id = ?');
    foreach ($phones as $p) {
        $ins->execute([now(), $p, $message, $kind, 'queued', $ref[0], (int)$ref[1], $by]);
        $id = (int)db()->lastInsertId();
        if (empty($c['enabled'])) { $upd->execute(['disabled', null, 'SMS is switched off in config.php', now(), $id]); $res['failed']++; $res['error'] = 'SMS is switched off in config.php'; continue; }
        $f = ['mobile' => $p, 'senderid' => $c['sender_id'] ?? '', 'msg' => $message, 'sendMethod' => 'quick',
              'msgType' => preg_match('/[^\x00-\x7F]/', $message) ? 'unicode' : 'text', 'duplicatecheck' => 'true'];
        if (!empty($c['test_mode'])) $f['test'] = 'true';
        $r = hp_request('POST', '/send', $f);
        $pid = isset($r['data']) ? hp_find($r['data'], ['transactionid', 'msgid', 'messageid', 'uuid']) : null;
        if ($r['ok']) { $upd->execute([!empty($c['test_mode']) ? 'test' : 'sent', $pid !== null ? (string)$pid : null, null, now(), $id]); $res['sent']++; }
        else { $upd->execute(['failed', $pid !== null ? (string)$pid : null, mb_substr($r['error'], 0, 255), now(), $id]); $res['failed']++; $res['error'] = $r['error']; }
    }
    return $res;
}

function sms_balance(): ?string {
    if (empty(sms_cfg()['enabled'])) return null;
    $r = hp_request('GET', '/account/readstatus');
    $v = isset($r['data']) ? hp_find($r['data'], ['smsbalance', 'balance', 'credit', 'credits', 'availablecredit', 'smscredit']) : null;
    return $v !== null ? (string)$v : null;
}

/* Points HostPinnacle's delivery reports at api/sms-delivery.php. */
function sms_register_webhook(string $url): array {
    $read = hp_request('GET', '/webhook/read');
    $exists = $read['ok'] && hp_find($read['data'], ['smswebhook', 'webhook', 'url']) !== null;
    return hp_request('POST', $exists ? '/webhook/update' : '/webhook/create', ['smswebhook' => $url]);
}

/* ---------- SMTP (SSL on 465 or STARTTLS on 587, AUTH LOGIN; no Composer needed) ---------- */

function smtp_read($fp): string {
    $out = '';
    while (($line = fgets($fp, 1024)) !== false) { $out .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
    return $out;
}
function smtp_cmd($fp, ?string $cmd, array $expect): void {
    if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
    $reply = smtp_read($fp);
    if (!in_array((int)substr($reply, 0, 3), $expect, true)) {
        $shown = ($cmd !== null && stripos($cmd, 'AUTH') !== 0 && strlen($cmd) < 80 && strpos($cmd, ' ') !== false) ? $cmd : 'login';
        throw new RuntimeException(trim($shown . ' -> ' . $reply));
    }
}
function mime_header(string $s): string { return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }

/* Sends a plain-text e-mail and logs it. Returns null on success, or the error. */
function send_mail(array $to, string $subject, string $body, array $ref = ['', 0]): ?string {
    $c = smtp_cfg();
    $to = array_values(array_filter(array_map('trim', $to), fn($a) => (bool)filter_var($a, FILTER_VALIDATE_EMAIL)));
    $error = null;
    if (!$to) $error = 'No valid recipient addresses.';
    elseif (empty($c['enabled'])) $error = 'E-mail is switched off in config.php';
    else {
        $fp = null;
        try {
            $secure = $c['secure'] ?? 'ssl';
            $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . (int)$c['port'], $errno, $errstr, 20);
            if (!$fp) throw new RuntimeException('Cannot connect to ' . $c['host'] . ':' . $c['port'] . ' (' . $errstr . ')');
            stream_set_timeout($fp, 20);
            $helo = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
            smtp_cmd($fp, null, [220]);
            smtp_cmd($fp, $helo, [250]);
            if ($secure === 'tls') {
                smtp_cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
                smtp_cmd($fp, $helo, [250]);
            }
            if (!empty($c['user'])) { smtp_cmd($fp, 'AUTH LOGIN', [334]); smtp_cmd($fp, base64_encode($c['user']), [334]); smtp_cmd($fp, base64_encode((string)$c['pass']), [235]); }
            smtp_cmd($fp, 'MAIL FROM:<' . $c['from_email'] . '>', [250]);
            foreach ($to as $a) smtp_cmd($fp, 'RCPT TO:<' . $a . '>', [250, 251]);
            smtp_cmd($fp, 'DATA', [354]);
            $domain = substr((string)strrchr($c['from_email'], '@'), 1) ?: 'localhost';
            $head = ['Date: ' . date('r'), 'From: ' . mime_header($c['from_name'] ?? 'Nyakundi 2027') . ' <' . $c['from_email'] . '>', 'To: ' . implode(', ', $to),
                     'Subject: ' . mime_header($subject), 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>', 'MIME-Version: 1.0',
                     'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
            // A base64 body never has a line starting with ".", so no dot-stuffing is needed.
            fwrite($fp, implode("\r\n", $head) . "\r\n\r\n" . chunk_split(base64_encode($body)) . ".\r\n");
            smtp_cmd($fp, null, [250]);
            smtp_cmd($fp, 'QUIT', [221]);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if (is_resource($fp)) fclose($fp);
    }
    db()->prepare('INSERT INTO email_log (created_at, to_addr, subject, body, status, error, record_type, record_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([now(), implode(', ', $to) ?: '(none)', mb_substr($subject, 0, 255), $body, $error ? 'failed' : 'sent', $error ? mb_substr($error, 0, 500) : null, $ref[0], (int)$ref[1]]);
    return $error;
}

/* ---------- after a website sign-up ---------- */

/* Confirmation SMS to the person, optional SMS alert to staff, and an e-mail to the team. */
function notify_signup(string $type, int $id, array $r): void {
    $table = $type . 's';
    $sms = sms_cfg();
    $first = explode(' ', trim($r['name']))[0];
    $tpl = (string)($sms['templates'][$type] ?? '');
    if ($tpl !== '') {
        sms_send([$r['phone']], strtr($tpl, ['{name}' => $first, '{ward}' => $r['ward'] ?? '', '{station}' => $r['station'] ?? '', '{stream}' => $r['stream'] ?? '',
            '{amount}' => isset($r['amount_ksh']) ? number_format((int)$r['amount_ksh']) : '']), 'confirmation', 'website', [$table, $id]);
    }
    $label = ['agent' => 'Polling agent', 'volunteer' => 'Volunteer', 'pledge' => 'Pledge'][$type];
    $place = array_filter([$r['constituency'] ?? '', $r['ward'] ?? '', $r['station'] ?? '', !empty($r['stream']) ? 'Stream ' . $r['stream'] : '']);
    $summary = $label . ': ' . $r['name'] . ', ' . $r['phone'] . (isset($r['amount_ksh']) ? ', Ksh ' . number_format((int)$r['amount_ksh']) : '') . ($place ? ', ' . implode(' > ', $place) : '');
    if ($sms['alert_numbers']) sms_send($sms['alert_numbers'], 'New ' . $summary, 'alert', 'website', [$table, $id]);

    $notify = smtp_cfg()['notify'];
    if ($notify) {
        $lines = ['A new ' . strtolower($label) . ' signed up on the website.', ''];
        foreach ($r as $k => $v) if ($v !== '' && $v !== null) $lines[] = str_pad(ucfirst(str_replace('_', ' ', $k)) . ':', 18) . $v;
        $lines[] = str_pad('Received:', 18) . gmdate('j M Y, H:i') . ' UTC';
        $lines[] = '';
        $lines[] = 'Follow up in the back office: https://' . ($_SERVER['HTTP_HOST'] ?? 'kucaparty.com') . '/backoffice/?p=' . $table . '&q=' . rawurlencode($r['phone']);
        send_mail($notify, 'New ' . $summary, implode("\n", $lines), [$table, $id]);
    }
}
