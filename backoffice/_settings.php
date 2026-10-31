<?php
/* Back office settings (administrators only): HostPinnacle SMS, the messages sent after a sign-up, and SMTP e-mail. */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_settings.php') { http_response_code(404); exit; }

/* Placeholder values for the sample person of one form, exactly as a real sign-up fills them. */
function sample_for(string $t, bool $toPerson = true): array { return signup_values($t, SAMPLE_RECORDS[$t], $toPerson); }

function sms_parts(string $text): int {
    $n = mb_strlen($text);
    $per = preg_match('/[^\x00-\x7F]/', $text) ? ($n > 70 ? 67 : 70) : ($n > 160 ? 153 : 160);
    return max(1, (int)ceil($n / $per));
}

/* Who last changed any setting starting with one of $prefixes, and when. */
function changed_line(string ...$prefixes): string {
    $last = null;
    foreach (settings() as $k => $r) foreach ($prefixes as $p) if (strpos($k, $p) === 0 && $r['updated_by'] !== 'system' && (!$last || $r['updated_at'] > $last['updated_at'])) $last = $r;
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
function messages_on(): int {
    $n = 0;
    foreach (array_keys(TEMPLATE_DEFAULTS) as $t) $n += (template_on($t) ? 1 : 0) + (mail_template($t)['on'] ? 1 : 0);
    return $n;
}

/* Warns about placeholders a message uses that its form does not fill. */
function unknown_tags(string $text, array $allowed): array {
    preg_match_all('/\{[a-z]+\}/', $text, $m);
    return array_values(array_diff(array_unique($m[0]), $allowed));
}

/* Handles the settings forms. Returns [message, is_error], or null if $a is not a settings action. */
function settings_handle(string $a, array $me): ?array {
    $by = $me['username'];
    $post = fn(string $k) => trim((string)($_POST[$k] ?? ''));
    $text = fn(string $k) => trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));

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

    // Test SMS: a plain test, a saved confirmation (type = volunteer/agent/pledge) or the team alert (type = alert).
    if ($a === 'sms_test') {
        $to = $post('to');
        if (kenya_phone($to) === '') return ['Enter a valid phone number, for example 0712 345 678.', true];
        $t = $post('type');
        if (isset(TEMPLATE_DEFAULTS[$t])) $msg = fill(template_text($t), sample_for($t));
        elseif ($t === 'alert') $msg = fill(alert_template()['sms'], sample_for('volunteer', false));
        else $msg = 'Test SMS from the Nyakundi 2027 back office. If you can read this, SMS works.';
        $r = sms_send([$to], $msg, 'test', $by);
        if (!$r['sent']) return ['The test SMS was not sent: ' . $r['error'], true];
        return ['Test SMS sent to ' . $to . (sms_cfg()['test_mode'] ? '. Test mode is on, so HostPinnacle accepted it but will not deliver it.' : '. It should arrive within a minute.'), false];
    }

    // Preview e-mail: a saved confirmation (type = volunteer/agent/pledge) or the team alert (type = alert), filled with sample details.
    if ($a === 'mail_test') {
        $to = $post('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['Enter a valid e-mail address for the preview.', true];
        $t = $post('type');
        if (isset(MAIL_DEFAULTS[$t])) {
            $m = mail_template($t); $v = sample_for($t);
            $err = send_mail([$to], '[Preview] ' . fill($m['subject'], $v), fill($m['body'], $v), ['', 0], 'confirmation');
        } else {
            $al = alert_template(); $v = sample_for('volunteer', false);
            $err = send_mail([$to], '[Preview] ' . fill($al['subject'], $v), fill($al['intro'], $v) . "\n\n(The sign-up details are listed here.)", ['', 0], 'alert');
        }
        return $err ? ['The preview was not sent: ' . $err, true] : ['Preview sent to ' . $to . '. It uses sample details.', false];
    }

    if ($a === 'save_messages') {
        $kv = []; $warn = [];
        foreach (TEMPLATE_DEFAULTS as $t => $def) {
            $label = FORM_LABELS[$t];
            $sms = preg_replace('/\s+/u', ' ', $text('tpl_' . $t)) ?: $def;
            $subject = preg_replace('/\s+/u', ' ', $text('mail_subject_' . $t)) ?: MAIL_DEFAULTS[$t]['subject'];
            $body = $text('mail_body_' . $t) ?: MAIL_DEFAULTS[$t]['body'];
            if (mb_strlen($sms) > 480) return [$label . ' SMS: keep it under 480 characters (3 SMS per person).', true];
            if (mb_strlen($subject) > 150) return [$label . ' e-mail: keep the subject under 150 characters.', true];
            if (mb_strlen($body) > 4000) return [$label . ' e-mail: keep the message under 4,000 characters.', true];
            foreach (['SMS' => $sms, 'e-mail' => $subject . ' ' . $body] as $where => $txt) {
                if ($u = unknown_tags($txt, TEMPLATE_TAGS[$t])) $warn[] = $label . ' ' . $where . ' uses ' . implode(', ', $u);
            }
            $kv += ['tpl.' . $t => $sms, 'tpl.' . $t . '.on' => !empty($_POST['on_' . $t]) ? '1' : '0',
                    'mail.' . $t . '.subject' => $subject, 'mail.' . $t . '.body' => $body, 'mail.' . $t . '.on' => !empty($_POST['mail_on_' . $t]) ? '1' : '0'];
        }
        $alert = ['subject' => preg_replace('/\s+/u', ' ', $text('alert_subject')) ?: ALERT_DEFAULTS['subject'],
                  'intro' => preg_replace('/\s+/u', ' ', $text('alert_intro')) ?: ALERT_DEFAULTS['intro'],
                  'sms' => preg_replace('/\s+/u', ' ', $text('alert_sms')) ?: ALERT_DEFAULTS['sms']];
        foreach ($alert as $k => $v) {
            if (mb_strlen($v) > 300) return ['Team alerts: keep each line under 300 characters.', true];
            if ($u = unknown_tags($v, ALERT_TAGS)) $warn[] = 'Team alert ' . $k . ' uses ' . implode(', ', $u);
            $kv['alert.' . $k] = $v;
        }
        settings_save($kv, $by);
        if ($warn) return ['Saved. Please check: ' . implode('; ', $warn) . '. That form does not collect those details, so they will come out blank.', true];
        return ['Messages saved. Everyone who signs up from now on gets the new wording.', false];
    }

    if ($a === 'save_email') {
        $port = (int)$post('port');
        $secure = in_array($post('secure'), ['ssl', 'tls', 'none'], true) ? $post('secure') : 'ssl';
        $from = $post('from_email');
        $reply = $post('reply_to');
        $notify = array_values(array_filter(preg_split('/[\s,;]+/', $post('notify')) ?: [], fn($x) => $x !== ''));
        $copy = array_values(array_filter(preg_split('/[\s,;]+/', $post('copy_to')) ?: [], fn($x) => $x !== ''));
        $badMail = array_filter(array_merge($notify, $copy, array_filter([$from, $reply])), fn($x) => !filter_var($x, FILTER_VALIDATE_EMAIL));
        if ($port < 1 || $port > 65535) return ['The port must be a number such as 465 or 587.', true];
        if ($badMail) return ['These are not valid e-mail addresses: ' . implode(', ', $badMail), true];
        $kv = ['smtp.enabled' => !empty($_POST['enabled']) ? '1' : '0', 'smtp.host' => $post('host'), 'smtp.port' => (string)$port, 'smtp.secure' => $secure,
               'smtp.user' => $post('user'), 'smtp.from_email' => $from, 'smtp.from_name' => $post('from_name') ?: 'Nyakundi 2027', 'smtp.reply_to' => $reply,
               'smtp.notify' => implode(', ', $notify), 'smtp.copy_to' => implode(', ', $copy)];
        if ((string)($_POST['pass'] ?? '') !== '') $kv['smtp.pass'] = (string)$_POST['pass'];
        settings_save($kv, $by);
        $c = smtp_cfg();
        if ($c['enabled'] && ($c['host'] === '' || $c['from_email'] === '')) {
            settings_save(['smtp.enabled' => '0'], $by);
            return ['Saved, but e-mail stays off until the server and the sender address are filled in.', true];
        }
        return ['E-mail settings saved. ' . ($c['enabled'] ? ($c['notify'] ? 'Sign-up alerts go to ' . implode(', ', $c['notify']) . '.' : 'No team alert recipients are set, so only confirmations to people who sign up are sent.') : 'E-mail is off.') . ' Send a test to make sure it works.', false];
    }
    return null;
}

/* Overview card for administrators. */
function messaging_panel(): string {
    [$sk, $sl, $sd] = sms_state(); [$ek, $el, $ed] = email_state();
    $on = messages_on();
    $since = gmdate('Y-m-d H:i:s', time() - 86400);
    $q = db()->prepare("SELECT COUNT(*) FROM sms_log WHERE created_at > ? AND status IN ('failed','undeliv','undelivered','rejected','expired')"); $q->execute([$since]); $smsBad = (int)$q->fetchColumn();
    $q = db()->prepare("SELECT COUNT(*) FROM email_log WHERE created_at > ? AND status = 'failed'"); $q->execute([$since]); $mailBad = (int)$q->fetchColumn();
    return '<h2>Messaging</h2><div class="cards">'
        . '<a class="card link" href="?p=settings&amp;tab=sms"><span class="pill ' . $sk . '">' . h($sl) . '</span><b>SMS</b><small>' . h($sd) . ($smsBad ? '<br><span class="gap">' . $smsBad . ' failed in the last 24 hours</span>' : '') . '</small></a>'
        . '<a class="card link" href="?p=settings&amp;tab=messages"><span class="pill ' . ($on ? 'on' : 'off') . '">' . $on . ' of 6 on</span><b>Messages to people who sign up</b><small>Confirmation SMS and e-mail for each form</small></a>'
        . '<a class="card link" href="?p=settings&amp;tab=email"><span class="pill ' . $ek . '">' . h($el) . '</span><b>E-mail</b><small>' . h($ed) . ($mailBad ? '<br><span class="gap">' . $mailBad . ' failed in the last 24 hours</span>' : '') . '</small></a>'
        . '</div>';
}

/* One editable message part: inputs, placeholder buttons, preview. */
function chips(array $tags): string {
    return '<div class="chips">' . implode('', array_map(fn($t) => '<button type="button" class="chip" data-tag="' . h($t) . '">' . h($t) . '</button>', $tags))
        . '<button type="button" class="chip plain" data-reset>Use the default text</button></div>';
}

function settings_page(string $tab): void {
    $tab = in_array($tab, ['sms', 'messages', 'email'], true) ? $tab : 'sms';
    $csrf = '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
    $saved = fn(string $k) => has_setting($k) && setting($k) !== '';
    [$sk, $sl, $sd] = sms_state(); [$ek, $el, $ed] = email_state();
    $on = messages_on();

    echo '<h1>Settings</h1><p class="muted">Connect SMS and e-mail, and choose what people are told after they sign up. Only administrators can see this page.</p>';
    echo '<nav class="subnav">'
        . '<a href="?p=settings&amp;tab=sms"' . ($tab === 'sms' ? ' class="on"' : '') . '>HostPinnacle SMS <span class="pill ' . $sk . '">' . h($sl) . '</span></a>'
        . '<a href="?p=settings&amp;tab=messages"' . ($tab === 'messages' ? ' class="on"' : '') . '>Messages <span class="pill ' . ($on ? 'on' : 'off') . '">' . $on . '/6</span></a>'
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
            . '<label>Alert phones <small>(optional)</small><textarea name="alert_numbers" rows="2" placeholder="0723 636 511">' . h(implode(', ', $c['alert_numbers'])) . '</textarea><small>These numbers get a short SMS for every new sign-up. Its wording is under <a href="?p=settings&amp;tab=messages#alerts">Messages &gt; Team alerts</a>.</small></label>'
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
        $sms = sms_cfg(); $mailOn = smtp_cfg()['enabled'];
        echo '<p>Everyone who submits a form gets a confirmation <b>SMS</b>, and an <b>e-mail</b> too if they entered an address (it is optional on the forms). Click a blue word to insert that person\'s details. The previews use sample details.</p>';
        if (!$sms['enabled']) echo '<p class="err">SMS is off, so the SMS below are not sent yet. <a href="?p=settings&amp;tab=sms">Connect HostPinnacle</a>.</p>';
        if (!$mailOn) echo '<p class="err">E-mail is off, so the e-mails below are not sent yet. <a href="?p=settings&amp;tab=email">Set up the mail server</a>.</p>';
        echo '<form method="post" class="stack" id="msgs">' . $csrf . '<input type="hidden" name="action" value="save_messages">';
        foreach (TEMPLATE_DEFAULTS as $t => $def) {
            $sample = h((string)json_encode(sample_for($t)));
            $m = mail_template($t); $text = template_text($t);
            echo '<section class="card msg" data-sample="' . $sample . '"><h2>' . h(FORM_LABELS[$t]) . '</h2><div class="cols even">';
            // SMS
            echo '<div class="part stack"><div class="part-head"><b>SMS</b><label class="switch"><input type="checkbox" name="on_' . $t . '" value="1"' . (template_on($t) ? ' checked' : '') . '><span>Send</span></label></div>'
                . '<textarea name="tpl_' . $t . '" rows="4" maxlength="480" data-key="sms" data-default="' . h($def) . '">' . h($text) . '</textarea>'
                . chips(TEMPLATE_TAGS[$t]) . '<small class="muted" data-count="sms"></small>'
                . '<div class="phone"><div class="bubble" data-show="sms">' . h(fill($text, sample_for($t))) . '</div></div></div>';
            // E-mail
            echo '<div class="part stack"><div class="part-head"><b>E-mail</b><label class="switch"><input type="checkbox" name="mail_on_' . $t . '" value="1"' . ($m['on'] ? ' checked' : '') . '><span>Send</span></label></div>'
                . '<label>Subject<input name="mail_subject_' . $t . '" maxlength="150" value="' . h($m['subject']) . '" data-key="subject" data-default="' . h(MAIL_DEFAULTS[$t]['subject']) . '"></label>'
                . '<label>Message<textarea name="mail_body_' . $t . '" rows="8" maxlength="4000" data-key="body" data-default="' . h(MAIL_DEFAULTS[$t]['body']) . '">' . h($m['body']) . '</textarea></label>'
                . chips(TEMPLATE_TAGS[$t])
                . '<div class="mail"><div class="mail-top"><small>From</small> ' . h(smtp_cfg()['from_name']) . ' <small>&lt;' . h(smtp_cfg()['from_email'] ?: 'noreply@kucaparty.com') . '&gt;</small><br><small>Subject</small> <b data-show="subject">' . h(fill($m['subject'], sample_for($t))) . '</b></div>'
                . '<div class="mail-brand">Nyakundi <span>2027</span></div><div class="mail-body" data-show="body">' . h(fill($m['body'], sample_for($t))) . '</div>'
                . '<div class="mail-foot">' . h(MAIL_FOOTERS['confirmation']) . '</div></div></div>';
            echo '</div></section>';
        }
        // Team alerts
        $al = alert_template();
        echo '<section class="card msg" id="alerts" data-sample="' . h((string)json_encode(sample_for('volunteer', false))) . '"><h2>Team alerts</h2>'
            . '<p class="muted small">What the campaign team receives for every sign-up: an e-mail to the alert addresses (<a href="?p=settings&amp;tab=email">E-mail settings</a>) and an SMS to the alert phones (<a href="?p=settings&amp;tab=sms">SMS settings</a>). {summary} is the name, phone and place (or amount) in one line. The e-mail always lists every detail the person entered.</p>'
            . '<div class="cols even"><div class="part stack">'
            . '<label>E-mail subject<input name="alert_subject" maxlength="300" value="' . h($al['subject']) . '" data-key="asubject" data-default="' . h(ALERT_DEFAULTS['subject']) . '"></label>'
            . '<label>E-mail first line<input name="alert_intro" maxlength="300" value="' . h($al['intro']) . '" data-key="aintro" data-default="' . h(ALERT_DEFAULTS['intro']) . '"></label>'
            . '<label>SMS to alert phones<textarea name="alert_sms" rows="2" maxlength="300" data-key="asms" data-default="' . h(ALERT_DEFAULTS['sms']) . '">' . h($al['sms']) . '</textarea></label>'
            . chips(ALERT_TAGS) . '</div>'
            . '<div class="stack"><small class="muted">Preview for a volunteer sign-up</small><div class="mail"><div class="mail-top"><small>Subject</small> <b data-show="asubject">' . h(fill($al['subject'], sample_for('volunteer', false))) . '</b></div><div class="mail-body" data-show="aintro">' . h(fill($al['intro'], sample_for('volunteer', false))) . '</div></div>'
            . '<div class="phone"><div class="bubble" data-show="asms">' . h(fill($al['sms'], sample_for('volunteer', false))) . '</div></div></div></div></section>';
        echo '<div class="savebar"><button class="btn">Save all messages</button>' . changed_line('tpl.', 'mail.', 'alert.') . '</div></form>';

        // Previews to your own phone or inbox
        $opts = '';
        foreach (FORM_LABELS as $t => $l) $opts .= '<option value="' . $t . '">' . h($l) . '</option>';
        $opts .= '<option value="alert">Team alert</option>';
        echo '<div class="cards"><form method="post" class="card inline">' . $csrf . '<input type="hidden" name="action" value="sms_test"><b>Send a saved SMS to my phone</b><select name="type">' . $opts . '</select><input name="to" placeholder="0712 345 678" required><button class="btn sm">Send</button></form>'
            . '<form method="post" class="card inline">' . $csrf . '<input type="hidden" name="action" value="mail_test"><b>Send a saved e-mail to my inbox</b><select name="type">' . $opts . '</select><input name="to" type="email" placeholder="you@example.com" required><button class="btn sm">Send</button></form></div>';
        echo <<<'JS'
<script>
(function () {
  function fill(tpl, v) {
    var out = tpl; Object.keys(v).forEach(function (k) { out = out.split(k).join(v[k]); });
    return out.replace(/[ \t]{2,}/g, ' ').replace(/ +([,.!?])/g, '$1').trim();
  }
  function parts(s) { var n = s.length, uni = /[^\x00-\x7F]/.test(s), per = uni ? (n > 70 ? 67 : 70) : (n > 160 ? 153 : 160); return Math.max(1, Math.ceil(n / per)); }
  document.querySelectorAll('.msg').forEach(function (card) {
    var sample = JSON.parse(card.getAttribute('data-sample'));
    function update() {
      card.querySelectorAll('[data-key]').forEach(function (f) {
        var key = f.getAttribute('data-key'), shown = fill(f.value, sample);
        card.querySelectorAll('[data-show="' + key + '"]').forEach(function (el) { el.textContent = shown || ' '; });
        var c = card.querySelector('[data-count="' + key + '"]');
        if (c) c.textContent = f.value.length + ' characters, about ' + parts(shown) + ' SMS per person';
      });
    }
    card.querySelectorAll('.part').forEach(function (part) {
      var fields = part.querySelectorAll('[data-key]'), last = fields[fields.length - 1];
      fields.forEach(function (f) { f.addEventListener('focus', function () { last = f; }); f.addEventListener('input', update); });
      part.querySelectorAll('[data-tag]').forEach(function (b) {
        b.addEventListener('click', function () {
          var tag = b.getAttribute('data-tag'), a = last.selectionStart, z = last.selectionEnd;
          last.value = last.value.slice(0, a) + tag + last.value.slice(z); last.focus(); last.selectionStart = last.selectionEnd = a + tag.length; update();
        });
      });
      var reset = part.querySelector('[data-reset]');
      if (reset) reset.addEventListener('click', function () { fields.forEach(function (f) { f.value = f.getAttribute('data-default'); }); update(); });
    });
    update();
  });
})();
</script>
JS;
    }

    if ($tab === 'email') {
        $c = smtp_cfg();
        echo '<div class="cols"><div>';
        echo '<form method="post" class="card stack">' . $csrf . '<input type="hidden" name="action" value="save_email"><h2>Mail server</h2>'
            . '<label class="switch"><input type="checkbox" name="enabled" value="1"' . ($c['enabled'] ? ' checked' : '') . '><span>Send e-mail from the website <small>(team alerts and confirmations to people who sign up)</small></span></label>'
            . '<div class="two"><label>SMTP server<input name="host" value="' . h($c['host']) . '" placeholder="mail.kucaparty.com" spellcheck="false"></label>'
            . '<div class="two"><label>Port<input name="port" value="' . (int)$c['port'] . '" inputmode="numeric"></label><label>Security<select name="secure">';
        foreach (['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)', 'none' => 'None'] as $k => $l) echo '<option value="' . $k . '"' . ($c['secure'] === $k ? ' selected' : '') . '>' . $l . '</option>';
        echo '</select></label></div></div>'
            . '<div class="two"><label>Username<input name="user" value="' . h($c['user']) . '" placeholder="noreply@kucaparty.com" autocomplete="off" spellcheck="false"></label>'
            . '<label>Password<input type="password" name="pass" autocomplete="new-password" placeholder="' . ($c['pass'] !== '' ? 'Saved. Leave blank to keep it' : 'The mailbox password') . '"></label></div>'
            . '<div class="two"><label>From address<input name="from_email" value="' . h($c['from_email']) . '" placeholder="noreply@kucaparty.com"></label>'
            . '<label>From name<input name="from_name" value="' . h($c['from_name']) . '"></label></div>'
            . '<label>Reply-to address <small>(optional)</small><input name="reply_to" value="' . h($c['reply_to']) . '" placeholder="info@nyamira2027.com"><small>Where replies go when someone answers a confirmation e-mail. Leave empty to use the from address.</small></label>'
            . '<label>Send sign-up alerts to<textarea name="notify" rows="2" placeholder="you@example.com, team@example.com">' . h(implode(', ', $c['notify'])) . '</textarea><small>One or more addresses, separated by commas. Their wording is under <a href="?p=settings&amp;tab=messages#alerts">Messages &gt; Team alerts</a>.</small></label>'
            . '<label>Send a copy of every e-mail to <small>(optional)</small><textarea name="copy_to" rows="2" placeholder="you@example.com">' . h(implode(', ', $c['copy_to'])) . '</textarea><small>A blind copy of everything the website sends: team alerts, confirmations to people who sign up, and tests. People who sign up never see this address.</small></label>'
            . '<div><button class="btn">Save e-mail settings</button></div>' . changed_line('smtp.') . '</form>';
        echo '</div><div>';
        echo '<form method="post" class="card stack">' . $csrf . '<input type="hidden" name="action" value="test_email"><h2>Send a test</h2><input name="email" value="' . h(implode(', ', $c['notify'])) . '" placeholder="you@example.com" required><div><button class="btn ghost">Send test e-mail</button></div><p class="muted small">Every e-mail and its result is listed on the <a href="?p=email">E-mail</a> page.</p></form>';
        echo '<div class="card help"><h2>Where to find these</h2><ol class="small"><li>cPanel &gt; <b>Email Accounts</b>: create a mailbox such as <b>noreply@kucaparty.com</b>.</li><li>Next to it, <b>Connect Devices</b> shows the <b>outgoing server</b> and <b>SMTP port</b> (usually mail.kucaparty.com, 465, SSL).</li><li>The username is the full address; the password is the mailbox password.</li></ol></div>';
        echo '</div></div>';
    }
}
