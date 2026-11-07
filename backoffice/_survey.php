<?php
/* Supporter pulse survey: the short form at /survey.html that is shared in WhatsApp groups.
   Answers are saved by api/save.php (type "survey") and shown to the two administrators on the Survey tab.
   This is NOT a poll: only people who receive the link can answer, so the results describe the campaign's own network. */
declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_survey.php') { http_response_code(404); exit; }

/* Stored values are always these English labels; survey.html shows Kiswahili text for the same values. */
const SURVEY_CANDIDATES = ['Dr. Gideon Moseti Nyakundi', 'Amos Nyaribo', 'Timothy Bosire', 'Jerusha Momanyi', 'Ben Momanyi', 'Walter Nyambati', 'Swanya Ogeto'];
const SURVEY_OTHER      = ['Someone else', 'Undecided'];
const SURVEY_ISSUES     = ['Health services', 'Roads', 'Water', 'Jobs for youth', 'Farming and markets', 'Education', 'Corruption', 'Cost of living', 'Other'];
const SURVEY_HEARD      = ['WhatsApp', 'Facebook or other social media', 'Rally or meeting', 'Friend or family', 'Radio or TV', 'Had not heard of him before', 'Other'];
const SURVEY_AGES       = ['18-24', '25-34', '35-44', '45-54', '55+'];
const SURVEY_GENDERS    = ['Woman', 'Man', 'Prefer not to say'];
const SURVEY_REG        = ['Yes, in Nyamira', 'Not yet', 'Registered elsewhere'];

/* One answer per phone. Only a keyed fingerprint of the number is kept with the answers, never the number itself. */
function survey_phone_key(string $phone): string {
    $d = preg_replace('/\D/', '', $phone) ?? '';
    return hash('sha256', substr($d, -9) . '|survey|' . (cfg()['secret'] ?? ''));
}

/* Called from api/save.php for {"type":"survey", ...}. Uses that file's out(), s() and phone_ok(). */
function survey_save(array $in): void {
    $volId = 0; $rec = [];
    try {
        install_schema();
        // Many phones share one public address on Kenyan mobile networks, so the limit per address is generous.
        if (too_many('survey', 300, 3600)) out(429, ['ok' => false, 'error' => 'Too many answers from this network just now. Please try again in a while.']);
        if (empty($in['consent'])) out(400, ['ok' => false, 'error' => 'Please tick the consent box.']);
        $pick = function (string $k, array $allowed) use ($in): string { $v = s($in, $k, 80); return in_array($v, $allowed, true) ? $v : ''; };
        $ward = s($in, 'ward', 40); $const = ward_constituency($ward);
        if ($const === '') out(400, ['ok' => false, 'error' => 'Please choose your ward.']);
        $a = ['age' => $pick('age', SURVEY_AGES), 'gender' => $pick('gender', SURVEY_GENDERS), 'registered' => $pick('registered', SURVEY_REG),
              'issue' => $pick('issue', SURVEY_ISSUES), 'vote' => $pick('vote', array_merge(SURVEY_CANDIDATES, SURVEY_OTHER)), 'heard' => $pick('heard', SURVEY_HEARD)];
        if (in_array('', $a, true)) out(400, ['ok' => false, 'error' => 'Please answer every question.']);
        $phone = s($in, 'phone', 30);
        if (!phone_ok($phone)) out(400, ['ok' => false, 'error' => 'Please enter a valid phone number.']);
        $vol = !empty($in['volunteer']); $name = s($in, 'name');
        if ($vol && $name === '') out(400, ['ok' => false, 'error' => 'Please enter your name so the team can call you.']);
        $src = preg_replace('/[^a-z0-9-]/', '', mb_strtolower(s($in, 'src', 40))) ?: 'direct';
        $key = survey_phone_key($phone);
        $chk = db()->prepare('SELECT COUNT(*) FROM survey WHERE phone_key = ?'); $chk->execute([$key]);
        if ((int)$chk->fetchColumn() > 0) out(200, ['ok' => true, 'already' => true]);
        try {
            db()->prepare('INSERT INTO survey (created_at, src, constituency, ward, age, gender, registered, issue, vote, heard, volunteer, phone_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([now(), $src, $const, $ward, $a['age'], $a['gender'], $a['registered'], $a['issue'], $a['vote'], $a['heard'], $vol ? 1 : 0, $key]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') out(200, ['ok' => true, 'already' => true]);   // the same phone answered a moment ago
            throw $e;
        }
        if ($vol) {
            // The volunteer record does not include how the person said they would vote.
            $rec = ['name' => $name, 'phone' => $phone, 'constituency' => $const, 'ward' => $ward, 'station' => '', 'stream' => '', 'village' => '', 'age' => $a['age'], 'roles' => 'Signed up through the survey', 'message' => 'Top issue: ' . $a['issue'], 'email' => ''];
            db()->prepare('INSERT INTO volunteers (created_at, constituency, ward, station, stream, village, age, roles, message, name, phone, email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([now(), $const, $ward, '', '', '', $a['age'], $rec['roles'], $rec['message'], $name, $phone, '']);
            $volId = (int)db()->lastInsertId();
        }
        record_hit('survey');
    } catch (Throwable $e) {
        error_log('survey: ' . $e->getMessage());
        out(500, ['ok' => false, 'error' => 'server']);
    }
    echo json_encode(['ok' => true]);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); flush(); }
    ignore_user_abort(true);
    if ($volId) { try { notify_signup('volunteer', $volId, $rec); } catch (Throwable $e) { error_log('survey notify: ' . $e->getMessage()); } }
}

/* ---------- back office ---------- */
function survey_where(): array {
    $w = []; $a = [];
    $src = (string)($_GET['g'] ?? ''); $c = (string)($_GET['c'] ?? '');
    if ($src !== '') { $w[] = 'src = ?'; $a[] = $src; }
    if ($c !== '' && isset(CONSTITUENCIES[$c])) { $w[] = 'constituency = ?'; $a[] = $c; }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $a];
}

function survey_export(): void {
    [$w, $a] = survey_where();
    $s = db()->prepare("SELECT id, created_at, src AS whatsapp_group, constituency, ward, age, gender, registered, issue, vote, heard, volunteer FROM survey$w ORDER BY id");
    $s->execute($a);
    export_csv('survey', $s->fetchAll());
}

function survey_count(string $col, string $w, array $a): array {
    $s = db()->prepare("SELECT $col k, COUNT(*) n FROM survey$w GROUP BY $col ORDER BY n DESC");
    $s->execute($a);
    $out = []; foreach ($s->fetchAll() as $r) $out[(string)$r['k']] = (int)$r['n'];
    return $out;
}

function survey_table(string $title, array $labels, array $counts, int $total): string {
    $o = '<h2>' . h($title) . '</h2><table><thead><tr><th>Answer</th><th>Answers</th><th>Share</th></tr></thead><tbody>';
    foreach ($labels as $l) {
        $n = $counts[$l] ?? 0; $pc = $total ? round(100 * $n / $total) : 0;
        $o .= '<tr><td>' . h($l) . '</td><td>' . number_format($n) . '</td><td><span class="bar"><i style="width:' . $pc . '%"></i></span> ' . $pc . '%</td></tr>';
    }
    return $o . '</tbody></table>';
}

function survey_page(): void {
    [$w, $a] = survey_where();
    $tot = db()->prepare("SELECT COUNT(*) FROM survey$w"); $tot->execute($a); $total = (int)$tot->fetchColumn();
    $vote = survey_count('vote', $w, $a); $issue = survey_count('issue', $w, $a); $heard = survey_count('heard', $w, $a);
    $reg = survey_count('registered', $w, $a); $age = survey_count('age', $w, $a); $gen = survey_count('gender', $w, $a);
    $vols = survey_count('volunteer', $w, $a)['1'] ?? 0;
    $today = db()->prepare("SELECT COUNT(*) FROM survey" . ($w ? $w . ' AND' : ' WHERE') . " created_at >= ?"); $today->execute(array_merge($a, [gmdate('Y-m-d') . ' 00:00:00']));
    $groups = array_map(fn($r) => $r['src'], db()->query('SELECT DISTINCT src FROM survey ORDER BY src')->fetchAll());
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'www.nyamira2027.com') . '/survey.html';

    echo '<h1>Supporter survey</h1>';
    echo '<p class="flash bad" style="margin:0 0 16px">These answers come only from people who received the survey link, mostly the campaign\'s own network. They show what supporters think and where the link is reaching. <b>They are not a poll of Nyamira voters:</b> do not publish them as one or compare them with the face-to-face survey.</p>';

    echo '<form method="get" class="filters"><input type="hidden" name="p" value="survey"><select name="g"><option value="">All WhatsApp groups</option>';
    foreach ($groups as $g) echo '<option' . (($_GET['g'] ?? '') === $g ? ' selected' : '') . '>' . h($g) . '</option>';
    echo '</select><select name="c"><option value="">All constituencies</option>';
    foreach (array_keys(CONSTITUENCIES) as $c) echo '<option' . (($_GET['c'] ?? '') === $c ? ' selected' : '') . '>' . h($c) . '</option>';
    $qs = $_GET; $qs['export'] = 1;
    echo '</select><button class="btn sm">Show</button><a class="btn sm ghost" href="?' . h(http_build_query($qs)) . '">Download CSV</a></form>';

    $inNy = $reg['Yes, in Nyamira'] ?? 0;
    echo '<div class="tiles"><div><b>' . number_format($total) . '</b><span>answers</span></div><div><b>' . number_format((int)$today->fetchColumn()) . '</b><span>today (UTC)</span></div>'
        . '<div><b>' . ($total ? round(100 * $inNy / $total) : 0) . '%</b><span>registered to vote in Nyamira</span></div><div><b>' . number_format($vols) . '</b><span>volunteer sign-ups (in Volunteers)</span></div></div>';

    // Link maker: one tagged link per WhatsApp group.
    echo '<div class="card" style="margin-top:18px"><h2>Make a link for a WhatsApp group</h2><p class="small muted">Give each group its own link. The results above can then be filtered by group, so you can see which groups answer.</p>'
        . '<div class="inline"><input id="gname" placeholder="Group name, e.g. Manga youth"><button type="button" class="btn sm" id="gmake">Make link</button></div>'
        . '<p id="gout" class="small" style="margin:10px 0 0"></p></div>'
        . '<script>(function(){var b=' . json_encode($base) . ',i=document.getElementById("gname"),o=document.getElementById("gout");document.getElementById("gmake").onclick=function(){var g=i.value.toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"").slice(0,40);if(!g){o.textContent="Type a group name first.";return}var u=b+"?g="+g,t="Help shape Nyamira’s future: a 2-minute survey on what the next governor should do. "+u;o.innerHTML="";var a=document.createElement("code");a.textContent=u;var w=document.createElement("a");w.href="https://wa.me/?text="+encodeURIComponent(t);w.target="_blank";w.rel="noopener";w.className="btn sm";w.style.marginLeft="8px";w.textContent="Share on WhatsApp";var c=document.createElement("button");c.type="button";c.className="btn sm ghost";c.style.marginLeft="6px";c.textContent="Copy";c.onclick=function(){navigator.clipboard&&navigator.clipboard.writeText(u);c.textContent="Copied"};o.append(a,w,c)}})();</script>';

    if (!$total) { echo '<p class="muted">No answers yet. Share a link to start.</p>'; return; }

    echo survey_table('Governor choice (if the election were today)', array_merge(SURVEY_CANDIDATES, SURVEY_OTHER), $vote, $total);

    // Governor choice by constituency (shares within each constituency).
    $s = db()->prepare("SELECT constituency, vote, COUNT(*) n FROM survey$w GROUP BY constituency, vote"); $s->execute($a);
    $m = []; $ct = [];
    foreach ($s->fetchAll() as $r) { $m[$r['vote']][$r['constituency']] = (int)$r['n']; $ct[$r['constituency']] = ($ct[$r['constituency']] ?? 0) + (int)$r['n']; }
    echo '<h2>Governor choice by constituency</h2><table><thead><tr><th>Candidate</th>';
    foreach (array_keys(CONSTITUENCIES) as $c) echo '<th>' . h($c) . ' <small>(' . number_format($ct[$c] ?? 0) . ')</small></th>';
    echo '</tr></thead><tbody>';
    foreach (array_merge(SURVEY_CANDIDATES, SURVEY_OTHER) as $cand) {
        echo '<tr><td>' . h($cand) . '</td>';
        foreach (array_keys(CONSTITUENCIES) as $c) { $n = $m[$cand][$c] ?? 0; echo '<td>' . (($ct[$c] ?? 0) ? round(100 * $n / $ct[$c]) . '%' : '-') . '</td>'; }
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<div class="cols even"><div>' . survey_table('Most important problem', SURVEY_ISSUES, $issue, $total) . '</div><div>' . survey_table('How they heard of Dr. Gideon', SURVEY_HEARD, $heard, $total) . '</div></div>';
    echo '<div class="cols even"><div>' . survey_table('Age', SURVEY_AGES, $age, $total) . '</div><div>' . survey_table('Gender', SURVEY_GENDERS, $gen, $total) . survey_table('Registered to vote', SURVEY_REG, $reg, $total) . '</div></div>';

    // Reach by ward and by group.
    $byWard = survey_count('ward', $w, $a);
    echo '<div class="cols even"><div><h2>Answers by ward</h2><table><thead><tr><th>Ward</th><th>Constituency</th><th>Answers</th></tr></thead><tbody>';
    foreach (CONSTITUENCIES as $c => $ws) foreach ($ws as $wd) echo '<tr><td>' . h($wd) . '</td><td>' . h($c) . '</td><td>' . number_format($byWard[$wd] ?? 0) . '</td></tr>';
    echo '</tbody></table></div><div><h2>Answers by WhatsApp group</h2><table><thead><tr><th>Group link</th><th>Answers</th><th>Chose Dr. Gideon</th></tr></thead><tbody>';
    $s = db()->prepare("SELECT src, COUNT(*) n, SUM(CASE WHEN vote = ? THEN 1 ELSE 0 END) g FROM survey$w GROUP BY src ORDER BY n DESC"); $s->execute(array_merge([SURVEY_CANDIDATES[0]], $a));
    foreach ($s->fetchAll() as $r) echo '<tr><td>' . h($r['src']) . '</td><td>' . number_format((int)$r['n']) . '</td><td>' . ((int)$r['n'] ? round(100 * (int)$r['g'] / (int)$r['n']) : 0) . '%</td></tr>';
    echo '</tbody></table></div></div>';
}
