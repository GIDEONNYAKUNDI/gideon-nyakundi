<?php
/* Back office settings (administrators only): HostPinnacle SMS, the SMS each form sends, and SMTP e-mail. */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_settings.php') { http_response_code(404); exit; }

const SAMPLE_VALUES = ['{name}' => 'Jane', '{ward}' => 'Rigoma', '{station}' => 'Rigoma Primary School', '{stream}' => '2', '{amount}' => '5,000'];

/* Sample details for one form; placeholders that form does not have come out blank, as in the real SMS. */
function sample_for(string $t): array {
    $out = [];
    foreach (SAMPLE_VALUES as $tag => $v) $out[$tag] = in_array($tag, TEMPLATE_TAGS[$t], true) ? $v : '';
    return $out;
}
function sms_parts(string $text): int {
    $n = mb_strlen($text);
    $per = preg_match('/[^\x00-\x7F]/', $text) ? ($n > 70 ? 67 : 70) : ($n > 160 ? 153 : 160);
    return max(1, (int)ceil($n / $per));
}

/* Who last changed any setting starting with $prefix, and when. */
function changed_line(string $prefix): string {
    $last = null;
    foreach (settings() as $k => $r) if (strpos($k, $prefix) === 0 && $r['updated_by'] !== 'system' && (!$last || $r['updated_at'] > $last['updated_at'])) $last = $r;
    return $last ? '<p class="muted small">Last changed by ' . h($last['updated_by']) . ' on ' . h(ke_time($last['updated_at'])) . '.</p>' : '';
}

function sms_state(): array {
    $c = sms_cfg();
    if (!$c['enabled']) return ['off', 'Off', $c['userid'] === '' ? 'Not connected to HostPinnacle yet' : 'Switched off'];
    return $c['test_mode'] ? ['test', 'Test mode', 'Accepted by HostPinnacle but not delivered'] : ['on', 'Live', 'Sending as ' . $c['sender_id']];
}
function email_state(): array {
    $c = smtp_cfg();
    if (!$c['enabled']) return ['off', 'Off', $c['host'] === '' ? 'No mail server set yet' : 'Switched off'];
    return ['on', 'On', 'Alerts to ' . implode(', ', $c['notify'])];
}

/* Handles the settings forms. Returns [message, is_error], or null if $a is not a settings action. */
function settings_handle(string $a, array $me): ?array {
    $by = $me['username'];
    $post = fn(string $k) => trim((string)($_POST[$k] ?? ''));

    if ($a === 'save_sms') {
        $sender = $post('sender_id');
        $alerts = array_values(array_filter(preg_split('/[\s,;]+/', $post('alert_numbers')) ?: [], fn($x) => $x !== ''));
        $bad = array_filter($alerts, fn($x) => kenya_phone($x) === '');
        if ($sender !== '' && !preg_match('/^[A-Za-z0-9 .\-]{1,11}$/', $sender)) return ['The sender ID can have at most 11 letters or numbers, spelled exactly as HostPinnacle approved it.', true];
        if ($bad) return ['These alert numbers are not valid phone numbers: ' . implode(', ', $bad), true];
        $kv = ['sms.enabled' => !empty($_POST['enabled']) ? '1' : '0', 'sms.test_mode' => !empty($_POST['test_mode']) ? '1' : '0',
               'sms.userid' => $post('userid'), 'sms.sender_id' => $sender, 'sms.alert_numbers' => implode(', ', $alerts)];
        foreach (['password' => 'sms.password', 'api_key' => 'sms.api_key'] as $f => $k) if ((string)($_POST[$f] ?? '') !== '') $kv[$k] = (string)$_POST[$f];
        settings_save($kv, $by);
        $c = sms_cfg();
        if ($c['enabled'] && in_array('', [$c['userid'], $c['password'], $c['api_key'], $c['sender_id']], true)) {
            settings_save(['sms.enabled' => '0'], $by);
            return ['Saved, but SMS stays off until the user ID, password, API key and sender ID are all filled in.', true];
        }
        return ['SMS settings saved. ' . ($c['enabled'] ? ($c['test_mode'] ? 'SMS is on in test mode: nothing is delivered yet.' : 'SMS is live.') : 'SMS is off.'), false];
    }

    if ($a === 'sms_check') {
        $c = sms_cfg();
        if ($c['userid'] === '' || $c['password'] === '' || $c['api_key'] === '') return ['Save the HostPinnacle user ID, password and API key first.', true];
        $r = hp_request('GET', '/account/readstatus');
        if (!$r['ok']) return ['HostPinnacle did not accept these details: ' . $r['error'], true];
        $bal = hp_find($r['data'], ['smsbalance', 'balance', 'credit', 'credits', 'availablecredit', 'smscredit']);
        return ['Connected to HostPinnacle.' . ($bal !== null ? ' SMS balance: ' . $bal . '.' : ''), false];
    }

    if ($a === 'sms_test') {
        $to = $post('to');
        if (kenya_phone($to) === '') return ['Enter a valid phone number for the test, for example 0712 345 678.', true];
        $t = $post('type');
        $msg = isset(TEMPLATE_DEFAULTS[$t]) ? strtr(template_text($t), sample_for($t)) : 'Test SMS from the Nyakundi 2027 back office. If you can read this, SMS works.';
        $r = sms_send([$to], $msg, 'test', $by);
        if (!$r['sent']) return ['The test SMS was not sent: ' . $r['error'], true];
        return ['Test SMS sent to ' . $to . (sms_cfg()['test_mode'] ? '. Test mode is on, so HostPinnacle accepted it but will not deliver it.' : '. It should arrive within a minute.'), false];
    }

    if ($a === 'save_messages') {
        $kv = []; $warn = [];
        foreach (TEMPLATE_DEFAULTS as $t => $def) {
            $text = trim(preg_replace('/\s+/u', ' ', (string)($_POST['tpl_' . $t] ?? '')) ?? '');
            if ($text === '') $text = $def;
            if (mb_strlen($text) > 480) return [FORM_LABELS[$t] . ': keep the message under 480 characters (3 SMS per person).', true];
            preg_match_all('/\{[a-z]+\}/', $text, $m);
            $unknown = array_diff(array_unique($m[0]), TEMPLATE_TAGS[$t]);
            if ($unknown) $warn[] = FORM_LABELS[$t] . ' uses ' . implode(', ', $unknown) . ', which that form does not collect';
            $kv['tpl.' . $t] = $text;
            $kv['tpl.' . $t . '.on'] = !empty($_POST['on_' . $t]) ? '1' : '0';
        }
        settings_save($kv, $by);
        if ($warn) return ['Saved. Please check: ' . implode('; ', $warn) . '. They will come out blank in the SMS.', true];
        return ['Confirmation messages saved. Everyone who signs up from now on gets the new wording.', false];
    }

    if ($a === 'save_email') {
        $port = (int)$post('port');
        $secure = in_array($post('secure'), ['ssl', 'tls', 'none'], true) ? $post('secure') : 'ssl';
        $from = $post('from_email');
        $notify = array_values(array_filter(preg_split('/[\s,;]+/', $post('notify')) ?: [], fn($x) => $x !== ''));
        $badMail = array_filter(array_merge($notify, $from !== '' ? [$from] : []), fn($x) => !filter_var($x, FILTER_VALIDATE_EMAIL));
        if ($port < 1 || $port > 65535) return ['The port must be a number such as 465 or 587.', true];
        if ($badMail) return ['These are not valid e-mail addresses: ' . implode(', ', $badMail), true];
        $kv = ['smtp.enabled' => !empty($_POST['enabled']) ? '1' : '0', 'smtp.host' => $post('host'), 'smtp.port' => (string)$port, 'smtp.secure' => $secure,
               'smtp.user' => $post('user'), 'smtp.from_email' => $from, 'smtp.from_name' => $post('from_name') ?: 'Nyakundi 2027 Website', 'smtp.notify' => implode(', ', $notify)];
        if ((string)($_POST['pass'] ?? '') !== '') $kv['smtp.pass'] = (string)$_POST['pass'];
        settings_save($kv, $by);
        $c = smtp_cfg();
        if ($c['enabled'] && ($c['host'] === '' || $c['from_email'] === '' || !$c['notify'])) {
            settings_save(['smtp.enabled' => '0'], $by);
            return ['Saved, but e-mail stays off until the server, the sender address and at least one alert recipient are filled in.', true];
        }
        return ['E-mail settings saved. ' . ($c['enabled'] ? 'Sign-up alerts go to ' . implode(', ', $c['notify']) . '.' : 'E-mail is off.') . ' Send a test to make sure it works.', false];
    }
    return null;
}

/* Overview card for administrators. */
function messaging_panel(): string {
    [$sk, $sl, $sd] = sms_state(); [$ek, $el, $ed] = email_state();
    $on = count(array_filter(array_keys(TEMPLATE_DEFAULTS), 'template_on'));
    $since = gmdate('Y-m-d H:i:s', time() - 86400);
    $q = db()->prepare("SELECT COUNT(*) FROM sms_log WHERE created_at > ? AND status IN ('failed','undeliv','undelivered','rejected','expired')"); $q->execute([$since]); $smsBad = (int)$q->fetchColumn();
    $q = db()->prepare("SELECT COUNT(*) FROM email_log WHERE created_at > ? AND status = 'failed'"); $q->execute([$since]); $mailBad = (int)$q->fetchColumn();
    return '<h2>Messaging</h2><div class="cards">'
        . '<a class="card link" href="?p=settings&amp;tab=sms"><span class="pill ' . $sk . '">' . h($sl) . '</span><b>SMS</b><small>' . h($sd) . ($smsBad ? '<br><span class="gap">' . $smsBad . ' failed in the last 24 hours</span>' : '') . '</small></a>'
        . '<a class="card link" href="?p=settings&amp;tab=messages"><span class="pill ' . ($on ? 'on' : 'off') . '">' . $on . ' of 3 on</span><b>Confirmation messages</b><small>The SMS people get after signing up</small></a>'
        . '<a class="card link" href="?p=settings&amp;tab=email"><span class="pill ' . $ek . '">' . h($el) . '</span><b>E-mail alerts</b><small>' . h($ed) . ($mailBad ? '<br><span class="gap">' . $mailBad . ' failed in the last 24 hours</span>' : '') . '</small></a>'
        . '</div>';
}

function settings_page(string $tab): void {
    $tab = in_array($tab, ['sms', 'messages', 'email'], true) ? $tab : 'sms';
    $csrf = '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
    $saved = fn(string $k) => has_setting($k) && setting($k) !== '';
    [$sk, $sl, $sd] = sms_state(); [$ek, $el, $ed] = email_state();
    $on = count(array_filter(array_keys(TEMPLATE_DEFAULTS), 'template_on'));

    echo '<h1>Settings</h1><p class="muted">Connect SMS and e-mail, and choose what people are told after they sign up. Only administrators can see this page.</p>';
    echo '<nav class="subnav">'
        . '<a href="?p=settings&amp;tab=sms"' . ($tab === 'sms' ? ' class="on"' : '') . '>HostPinnacle SMS <span class="pill ' . $sk . '">' . h($sl) . '</span></a>'
        . '<a href="?p=settings&amp;tab=messages"' . ($tab === 'messages' ? ' class="on"' : '') . '>Confirmation messages <span class="pill ' . ($on ? 'on' : 'off') . '">' . $on . '/3</span></a>'
        . '<a href="?p=settings&amp;tab=email"' . ($tab === 'email' ? ' class="on"' : '') . '>E-mail (SMTP) <span class="pill ' . $ek . '">' . h($el) . '</span></a></nav>';

    if ($tab === 'sms') {
        $c = sms_cfg();
        echo '<div class="cols"><div>';
        echo '<form method="post" class="card stack">' . $csrf . '<input type="hidden" name="action" value="save_sms"><h2>HostPinnacle account</h2>'
            . '<label class="switch"><input type="checkbox" name="enabled" value="1"' . ($c['enabled'] ? ' checked' : '') . '><span>Send SMS from the website</span></label>'
            . '<label class="switch"><input type="checkbox" name="test_mode" value="1"' . ($c['test_mode'] ? ' checked' : '') . '><span>Test mode <small>(HostPinnacle accepts messages but does not deliver them; untick to go live)</small></span></label>'
            . '<div class="two"><label>User ID<input name="userid" value="' . h($c['userid']) . '" autocomplete="off" spellcheck="false"></label>'
            . '<label>Password<input type="password" name="password" autocomplete="new-password" placeholder="' . ($saved('sms.password') || $c['password'] !== '' ? 'Saved. Leave blank to keep it' : 'Your portal password') . '"></label></div>'
            . '<label>API key<input type="password" name="api_key" autocomplete="off" placeholder="' . ($saved('sms.api_key') || $c['api_key'] !== '' ? 'Saved. Leave blank to keep it' : 'From Account > API Key in HostPinnacle') . '"></label>'
            . '<label>Sender ID<input name="sender_id" value="' . h($c['sender_id']) . '" maxlength="11" placeholder="e.g. NYAKUNDI" spellcheck="false"><small>The name people see as the sender. It must be approved in your HostPinnacle account.</small></label>'
            . '<label>Alert phones <small>(optional)</small><textarea name="alert_numbers" rows="2" placeholder="0723 636 511">' . h(implode(', ', $c['alert_numbers'])) . '</textarea><small>These numbers get a short SMS for every new sign-up.</small></label>'
            . '<div><button class="btn">Save SMS settings</button></div>' . changed_line('sms.') . '</form>';
        echo '</div><div>';
        echo '<div class="card stack"><h2>Check and test</h2>'
            . '<form method="post">' . $csrf . '<input type="hidden" name="action" value="sms_check"><button class="btn ghost">Check connection and balance</button></form>'
            . '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="action" value="sms_test"><input name="to" placeholder="Your phone, e.g. 0712 345 678" required><button class="btn sm">Send test SMS</button></form></div>';
        echo '<div class="card stack"><h2>Delivery reports</h2><p class="muted small">So each SMS changes from "sent" to "delivered" or "failed", HostPinnacle needs to know where to report. Press once after going live.</p>'
            . '<form method="post">' . $csrf . '<input type="hidden" name="action" value="webhook"><button class="btn ghost">Register delivery webhook</button></form></div>';
        echo '<div class="card help"><h2>Where to find these</h2><ol class="small"><li>Log in to <b>smsportal.hostpinnacle.co.ke</b>. The <b>user ID</b> and <b>password</b> are that login.</li><li><b>Account &gt; API Key</b>: create or copy the key.</li><li><b>Sender ID</b>: use the exact name HostPinnacle approved for you.</li><li>Save, press <b>Check connection</b>, send yourself a test, then untick test mode.</li></ol></div>';
        echo '</div></div>';
    }

    if ($tab === 'messages') {
        echo '<p>After someone submits a form on the website, they get this SMS. Click a blue word to insert it: it is replaced with that person\'s details. One SMS holds 160 characters; longer messages cost more per person.</p>';
        echo '<form method="post" class="stack">' . $csrf . '<input type="hidden" name="action" value="save_messages">';
        foreach (TEMPLATE_DEFAULTS as $t => $def) {
            $text = template_text($t);
            $chips = implode('', array_map(fn($tag) => '<button type="button" class="chip" data-tag="' . h($tag) . '">' . h($tag) . '</button>', TEMPLATE_TAGS[$t]));
            echo '<div class="card tpl" data-default="' . h($def) . '" data-sample="' . h((string)json_encode(sample_for($t))) . '"><div class="tpl-head"><h2>' . h(FORM_LABELS[$t]) . '</h2>'
                . '<label class="switch"><input type="checkbox" name="on_' . $t . '" value="1"' . (template_on($t) ? ' checked' : '') . '><span>Send this SMS</span></label></div>'
                . '<div class="cols"><div class="stack"><textarea name="tpl_' . $t . '" rows="4" maxlength="480">' . h($text) . '</textarea>'
                . '<div class="chips">' . $chips . '<button type="button" class="chip plain" data-reset>Use the default text</button></div>'
                . '<small class="count">' . mb_strlen($text) . ' characters, ' . sms_parts($text) . ' SMS per person</small></div>'
                . '<div><small class="muted">Preview with sample details</small><div class="bubble">' . h(strtr($text, sample_for($t))) . '</div></div></div></div>';
        }
        echo '<div><button class="btn">Save confirmation messages</button></div>' . changed_line('tpl.') . '</form>';
        echo '<form method="post" class="card inline">' . $csrf . '<input type="hidden" name="action" value="sms_test"><b>Send a saved message to my phone:</b><select name="type">';
        foreach (FORM_LABELS as $t => $l) echo '<option value="' . $t . '">' . h($l) . '</option>';
        echo '</select><input name="to" placeholder="0712 345 678" required><button class="btn sm">Send preview</button></form>';
        echo <<<JS
<script>
(function () {
  function parts(s) { var n = s.length, uni = /[^\\x00-\\x7F]/.test(s), per = uni ? (n > 70 ? 67 : 70) : (n > 160 ? 153 : 160); return Math.max(1, Math.ceil(n / per)); }
  document.querySelectorAll('.tpl').forEach(function (card) {
    var sample = JSON.parse(card.getAttribute('data-sample')), ta = card.querySelector('textarea'), count = card.querySelector('.count'), bubble = card.querySelector('.bubble');
    function update() {
      var s = ta.value, p = s;
      Object.keys(sample).forEach(function (k) { p = p.split(k).join(sample[k]); });
      bubble.textContent = p || ' ';
      count.textContent = s.length + ' characters, ' + parts(p) + ' SMS per person (with sample details)';
    }
    card.querySelectorAll('[data-tag]').forEach(function (b) {
      b.addEventListener('click', function () {
        var tag = b.getAttribute('data-tag'), a = ta.selectionStart, z = ta.selectionEnd;
        ta.value = ta.value.slice(0, a) + tag + ta.value.slice(z); ta.focus(); ta.selectionStart = ta.selectionEnd = a + tag.length; update();
      });
    });
    card.querySelector('[data-reset]').addEventListener('click', function () { ta.value = card.getAttribute('data-default'); update(); });
    ta.addEventListener('input', update); update();
  });
})();
</script>
JS;
    }

    if ($tab === 'email') {
        $c = smtp_cfg();
        echo '<div class="cols"><div>';
        echo '<form method="post" class="card stack">' . $csrf . '<input type="hidden" name="action" value="save_email"><h2>Mail server</h2>'
            . '<label class="switch"><input type="checkbox" name="enabled" value="1"' . ($c['enabled'] ? ' checked' : '') . '><span>E-mail the team about every new sign-up</span></label>'
            . '<div class="two"><label>SMTP server<input name="host" value="' . h($c['host']) . '" placeholder="mail.kucaparty.com" spellcheck="false"></label>'
            . '<div class="two"><label>Port<input name="port" value="' . (int)$c['port'] . '" inputmode="numeric"></label><label>Security<select name="secure">';
        foreach (['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)', 'none' => 'None'] as $k => $l) echo '<option value="' . $k . '"' . ($c['secure'] === $k ? ' selected' : '') . '>' . $l . '</option>';
        echo '</select></label></div></div>'
            . '<div class="two"><label>Username<input name="user" value="' . h($c['user']) . '" placeholder="noreply@kucaparty.com" autocomplete="off" spellcheck="false"></label>'
            . '<label>Password<input type="password" name="pass" autocomplete="new-password" placeholder="' . ($c['pass'] !== '' ? 'Saved. Leave blank to keep it' : 'The mailbox password') . '"></label></div>'
            . '<div class="two"><label>From address<input name="from_email" value="' . h($c['from_email']) . '" placeholder="noreply@kucaparty.com"></label>'
            . '<label>From name<input name="from_name" value="' . h($c['from_name']) . '"></label></div>'
            . '<label>Send sign-up alerts to<textarea name="notify" rows="2" placeholder="you@example.com, team@example.com">' . h(implode(', ', $c['notify'])) . '</textarea><small>One or more addresses, separated by commas.</small></label>'
            . '<div><button class="btn">Save e-mail settings</button></div>' . changed_line('smtp.') . '</form>';
        echo '</div><div>';
        echo '<form method="post" class="card stack">' . $csrf . '<input type="hidden" name="action" value="test_email"><h2>Send a test</h2><input name="email" value="' . h(implode(', ', $c['notify'])) . '" placeholder="you@example.com" required><div><button class="btn ghost">Send test e-mail</button></div><p class="muted small">Every e-mail and its result is listed on the <a href="?p=email">E-mail</a> page.</p></form>';
        echo '<div class="card help"><h2>Where to find these</h2><ol class="small"><li>cPanel &gt; <b>Email Accounts</b>: create a mailbox such as <b>noreply@kucaparty.com</b>.</li><li>Next to it, <b>Connect Devices</b> shows the <b>outgoing server</b> and <b>SMTP port</b> (usually mail.kucaparty.com, 465, SSL).</li><li>The username is the full address; the password is the mailbox password.</li></ol></div>';
        echo '</div></div>';
    }
}
