<?php
/* Tallying center sign-in for center staff (data clerks, verifiers, legal desk).
   Staff accounts live in their own table with their own session cookie, so a staff sign-in can never
   open the main back office (volunteers, agents, voters, pledges, SMS, settings). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/_tally_page.php';

security_headers();
if (!cfg()) { http_response_code(503); exit('Not set up.'); }
try { install_schema(); tally_schema(); } catch (Throwable $e) { error_log('tally: ' . $e->getMessage()); http_response_code(503); exit('The tallying center cannot reach the database.'); }

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('nyk_tc');
session_set_cookie_params(['lifetime' => 0, 'path' => '/backoffice/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (!empty($_SESSION['sid']) && (time() - ($_SESSION['seen'] ?? 0)) > 2 * 3600) { $_SESSION = []; session_regenerate_id(true); }
$_SESSION['seen'] = time();

if (($_GET['p'] ?? '') === 'logout') { $_SESSION = []; session_destroy(); header('Location: tally.php'); exit; }

function staff_row(int $id): ?array {
    $s = db()->prepare('SELECT * FROM tally_staff WHERE id = ? AND active = 1'); $s->execute([$id]);
    return $s->fetch() ?: null;
}
function staff_page(string $title, string $body): void {
    page_head($title);
    echo '<main class="narrow"><h1>' . h($title) . '</h1>' . $body . '</main>'; page_foot(); exit;
}
function hidden_csrf(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !csrf_ok()) { http_response_code(400); exit('Session expired. Go back and reload the page.'); }
$err = '';

/* Signed in (password and code done): the tallying center itself. */
if (!empty($_SESSION['sid'])) {
    $st = staff_row((int)$_SESSION['sid']);
    if (!$st) { $_SESSION = []; session_regenerate_id(true); header('Location: tally.php'); exit; }
    if ((int)$st['must_change']) {
        if ($post && ($_POST['action'] ?? '') === 'newpass') {
            $pw = (string)($_POST['new'] ?? '');
            if (strlen($pw) < 12) $err = 'The password must be at least 12 characters.';
            elseif ($pw !== ($_POST['new2'] ?? '')) $err = 'The two passwords do not match.';
            elseif (password_verify($pw, $st['pass_hash'])) $err = 'Choose a new password, not the first one you were given.';
            else {
                db()->prepare('UPDATE tally_staff SET pass_hash = ?, must_change = 0 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $st['id']]);
                tally_audit($st['username'], 'password_set');
                header('Location: tally.php'); exit;
            }
        }
        staff_page('Choose your own password', ($err ? '<p class="err">' . h($err) . '</p>' : '') . '<p class="muted">Only you should know it. Do not write it down near the center.</p><form method="post" class="stack">' . hidden_csrf() . '<input type="hidden" name="action" value="newpass"><label>New password (at least 12 characters)<input type="password" name="new" required autocomplete="new-password"></label><label>Repeat it<input type="password" name="new2" required autocomplete="new-password"></label><button class="btn">Save and continue</button></form>');
    }
    $me = ['id' => (int)$st['id'], 'username' => $st['username'], 'full_name' => $st['full_name'], 'role' => 'staff', 'tally_role' => $st['tally_role']];
    tally_page($me);
}

/* Step 2: the code from the authenticator app (or setting it up the first time). */
$pending = (int)($_SESSION['t_pending'] ?? 0);
if ($pending && (time() - (int)($_SESSION['t_pending_at'] ?? 0)) > 600) { unset($_SESSION['t_pending'], $_SESSION['t_new']); $pending = 0; $err = 'That took too long. Sign in again.'; }
if ($pending) {
    $st = staff_row($pending);
    if (!$st) { $_SESSION = []; header('Location: tally.php'); exit; }
    $setup = $st['totp_secret'] === '';
    if ($setup && empty($_SESSION['t_new'])) $_SESSION['t_new'] = b32_encode(random_bytes(20));
    $secret = $setup ? (string)$_SESSION['t_new'] : $st['totp_secret'];
    if ($post && isset($_POST['code'])) {
        if (too_many('tcode', 8, 900)) $err = 'Too many attempts. Wait 15 minutes and try again.';
        elseif (totp_ok($secret, (string)$_POST['code'])) {
            if ($setup) { db()->prepare('UPDATE tally_staff SET totp_secret = ? WHERE id = ?')->execute([$secret, $st['id']]); tally_audit($st['username'], 'two_step_set_up'); }
            db()->prepare('UPDATE tally_staff SET last_login = ? WHERE id = ?')->execute([now(), $st['id']]);
            tally_audit($st['username'], 'signed_in');
            unset($_SESSION['t_pending'], $_SESSION['t_pending_at'], $_SESSION['t_new']);
            session_regenerate_id(true); $_SESSION['sid'] = (int)$st['id'];
            header('Location: tally.php'); exit;
        } else { record_hit('tcode'); usleep(600000); $err = 'That code is not right. Use the 6-digit code showing in the app now.'; }
    }
    $form = '<form method="post" class="stack">' . hidden_csrf() . '<label>6-digit code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus></label><button class="btn">Continue</button></form><p><a href="?p=logout">Cancel</a></p>';
    if ($setup) {
        $uri = 'otpauth://totp/' . rawurlencode('Nyakundi 2027 Tally:' . $st['username']) . '?secret=' . $secret . '&issuer=' . rawurlencode('Nyakundi 2027 Tally');
        staff_page('Set up two-step sign-in', ($err ? '<p class="err">' . h($err) . '</p>' : '') . '<p class="muted">Every tallying center account needs a code from your phone as well as the password.</p><ol class="small"><li>Install <b>Google Authenticator</b> or <b>Microsoft Authenticator</b>.</li><li>Add an account, choose <b>Enter a setup key</b>. Name: <b>Nyakundi 2027 Tally</b>. Key: <code style="font-size:1.05rem;letter-spacing:.08em">' . h(trim(chunk_split($secret, 4, ' '))) . '</code> (time-based).</li><li>On this phone you can instead <a href="' . h($uri) . '">open this link</a>.</li><li>Type the 6-digit code the app shows.</li></ol>' . $form);
    }
    staff_page('Two-step sign-in', ($err ? '<p class="err">' . h($err) . '</p>' : '') . '<p class="muted">Enter the 6-digit code for Nyakundi 2027 Tally from your authenticator app.</p>' . $form);
}

/* Step 1: username and password. */
if ($post) {
    $user = mb_strtolower(trim((string)($_POST['username'] ?? '')));
    $ukey = hash('sha256', 't|' . $user . '|' . (cfg()['secret'] ?? ''));
    if (too_many('tlogin', 8, 900) || ($user !== '' && too_many('tloginu', 6, 900, $ukey))) $err = 'Too many attempts. Wait 15 minutes and try again.';
    else {
        $s = db()->prepare('SELECT * FROM tally_staff WHERE username = ? AND active = 1'); $s->execute([$user]); $st = $s->fetch();
        if ($st && password_verify((string)($_POST['password'] ?? ''), $st['pass_hash'])) {
            session_regenerate_id(true); $_SESSION['t_pending'] = (int)$st['id']; $_SESSION['t_pending_at'] = time();
            header('Location: tally.php'); exit;
        }
        record_hit('tlogin'); if ($user !== '') record_hit('tloginu', $ukey); usleep(600000);
        if ($user !== '') tally_audit($user, 'sign_in_failed');
        $err = 'Wrong username or password.';
    }
}
staff_page('Tallying center sign in', ($err ? '<p class="err">' . h($err) . '</p>' : '') . '<p class="muted">For tallying center staff only. Every sign-in is recorded.</p><form method="post" class="stack">' . hidden_csrf() . '<label>Username<input name="username" required autocomplete="username"></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><button class="btn">Sign in</button></form>');
