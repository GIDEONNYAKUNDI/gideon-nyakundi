<?php
/* Page layout shared by the back office (index.php) and the tallying center sign-in (tally.php). */
declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_layout.php') { http_response_code(404); exit; }

function page_head(string $title, ?array $me = null, string $p = ''): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . h($title) . ' · Nyakundi 2027</title><style>
:root{--blue:#13264d;--red:#c0272d;--gold:#d4b04c;--green:#2e7d4f;--ink:#1b2333;--muted:#5d6779;--line:#dde2ea;--bg:#f4f6f9}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:var(--bg)}
header{background:var(--blue);color:#fff;border-bottom:3px solid var(--red);padding:12px 20px;display:flex;flex-wrap:wrap;gap:10px 22px;align-items:center}
header .brand{font-weight:800;font-size:1.1rem;color:#fff;text-decoration:none}header .brand span{color:var(--gold)}
header nav{display:flex;flex-wrap:wrap;gap:4px 16px;flex:1}header nav a{color:#dfe6f3;text-decoration:none;padding:4px 0;border-bottom:2px solid transparent}header nav a.on,header nav a:hover{color:#fff;border-color:var(--gold)}
header .who{color:#c9d2e3;font-size:.9rem}header .who a{color:#fff}
main{max-width:1280px;margin:0 auto;padding:24px 20px 60px}main.narrow,.narrow{max-width:520px}
h1{font-size:1.7rem;margin:6px 0 16px;color:var(--blue)}h2{font-size:1.2rem;margin:30px 0 10px;color:var(--blue)}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.tiles div{background:#fff;border:1px solid var(--line);border-top:3px solid var(--gold);padding:14px 16px;display:grid}.tiles b{font-size:1.6rem;color:var(--blue)}.tiles span{color:var(--muted)}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);margin-top:12px;font-size:.93rem}th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{background:#eef1f6;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
small{color:var(--muted)}a{color:var(--blue)}.gap{color:var(--red);font-weight:600}
.bar{display:inline-block;width:110px;height:9px;background:#e5e9f0;vertical-align:middle}.bar i{display:block;height:100%;background:var(--green)}
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px}
input,select,textarea{font:inherit;padding:7px 9px;border:1px solid #c5ccd8;border-radius:3px;background:#fff;max-width:100%}textarea{width:100%;resize:vertical}
.tag{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.8rem;font-weight:600;background:#eef1f6;color:var(--muted)}.tag.ok{background:#e3f3e9;color:#1f6b40}.tag.bad{background:#fbe4e4;color:#a3222a}.tag.wait{background:#fbf1d6;color:#7a5a0c}
details summary{cursor:pointer}
.flash.bad{background:#fbe9e9;border-left-color:var(--red)}.small{font-size:.86rem}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.card{background:#fff;border:1px solid var(--line);border-radius:6px;padding:18px 20px;margin-bottom:16px}.card h2{margin:0 0 4px;font-size:1.08rem}
.card.link{display:grid;gap:4px;text-decoration:none;color:var(--ink);margin:0;border-top:3px solid var(--blue)}.card.link:hover{border-color:var(--gold)}.card.link b{color:var(--blue);font-size:1.05rem}
.card.help{background:#f8f9fc}.card.help ol{margin:6px 0 0;padding-left:18px;display:grid;gap:6px}
.pill{display:inline-block;justify-self:start;padding:1px 9px;border-radius:10px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#eef1f6;color:var(--muted)}.pill.on{background:#e3f3e9;color:#1f6b40}.pill.test{background:#fbf1d6;color:#7a5a0c}.pill.off{background:#fbe4e4;color:#a3222a}
.subnav{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 18px;border-bottom:1px solid var(--line)}.subnav a{padding:10px 14px;text-decoration:none;color:var(--muted);border-bottom:3px solid transparent;margin-bottom:-1px;font-weight:600}.subnav a.on{color:var(--blue);border-color:var(--red)}
.cols{display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:18px;align-items:start}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.stack label small,.stack label input,.stack label textarea,.stack label select{font-weight:400}.switch{display:flex!important;grid-template-columns:none;gap:10px;align-items:flex-start;font-weight:600}.switch input{width:18px;height:18px;margin-top:2px;accent-color:var(--green)}
.inline{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.inline input{flex:1;min-width:180px}
.tpl-head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}
.chips{display:flex;flex-wrap:wrap;gap:6px}.chip{font:inherit;font-size:.85rem;padding:3px 10px;border-radius:12px;border:1px solid #b9c6e0;background:#eef3fc;color:var(--blue);cursor:pointer}.chip.plain{background:#fff;border-color:var(--line);color:var(--muted)}
.bubble{margin-top:6px;background:#e9f6ec;border:1px solid #cfe8d6;border-radius:14px 14px 14px 4px;padding:12px 14px;white-space:pre-wrap;line-height:1.45;max-width:420px}
.cols.even{grid-template-columns:1fr 1fr}.part-head{display:flex;justify-content:space-between;align-items:center;gap:10px;border-bottom:1px solid var(--line);padding-bottom:6px}
.phone{background:#f1f3f7;border-radius:14px;padding:12px}.phone .bubble{margin:0;background:#fff;border-color:var(--line)}
.mail{border:1px solid var(--line);border-radius:6px;overflow:hidden;background:#f4f6f9;font-size:.92rem}.mail-top{background:#fff;padding:9px 12px;border-bottom:1px solid var(--line);line-height:1.6}.mail-top small{color:var(--muted);display:inline-block;width:56px}
.mail-brand{background:var(--blue);color:#fff;font-weight:800;padding:9px 14px;border-bottom:3px solid var(--red);margin:10px 10px 0}.mail-brand span{color:var(--gold)}
.mail-body{background:#fff;margin:0 10px;padding:14px;white-space:pre-wrap;line-height:1.5;border:1px solid var(--line);border-top:0}.mail-foot{font-size:.75rem;color:var(--muted);padding:8px 12px 12px}
.savebar{position:sticky;bottom:0;background:var(--bg);padding:12px 0;border-top:1px solid var(--line);display:flex;gap:16px;align-items:center;flex-wrap:wrap}.savebar p{margin:0}
@media (max-width:860px){.cols,.two,.cols.even{grid-template-columns:1fr}}
.stack{display:grid;gap:12px}.stack label{display:grid;gap:4px;font-weight:600}
.row-edit{display:flex;flex-wrap:wrap;gap:6px}.row-edit input{min-width:140px;flex:1}
.btn{font:inherit;font-weight:700;background:var(--red);color:#fff;border:0;padding:9px 16px;border-radius:3px;cursor:pointer;text-decoration:none;display:inline-block}.btn.sm{padding:6px 11px;font-size:.88rem}.btn.ghost{background:#fff;color:var(--blue);border:1px solid var(--blue)}
.flash{max-width:1280px;margin:14px auto 0;padding:10px 20px;background:#e8f4ec;border-left:4px solid var(--green)}.err{color:var(--red);font-weight:600}.muted{color:var(--muted)}.chk{display:flex;gap:6px;align-items:center}
@media (max-width:760px){table,thead,tbody,tr,td{display:block}thead{display:none}tr{border-bottom:2px solid var(--line);padding:6px 0}td{border:0;padding:4px 10px}}
</style></head><body><header><a class="brand" href="' . (($me['role'] ?? '') === 'staff' ? 'tally.php' : './') . '">Nyakundi <span>2027</span> · Back office</a>';
    if ($me && ($me['role'] ?? '') === 'staff') {
        echo '<nav><a href="?p=tally" class="on">Tallying center</a></nav><span class="who">' . h($me['full_name'] ?: $me['username']) . ' · ' . h(TALLY_ROLES[$me['tally_role']] ?? '') . ' · <a href="?p=logout">Sign out</a></span>';
    } elseif ($me) {
        $tabs = ['home' => 'Overview', 'gaps' => 'Streams', 'agents' => 'Agents', 'volunteers' => 'Volunteers', 'voters' => 'Voters'];
        if ($me['role'] === 'admin') { $tabs['pledges'] = 'Pledges'; $tabs['sms'] = 'SMS'; $tabs['email'] = 'E-mail'; $tabs['import'] = 'Import'; $tabs['settings'] = 'Settings'; $tabs['tally'] = 'Tallying'; }
        $tabs['account'] = 'Security';
        echo '<nav>'; foreach ($tabs as $k => $v) echo '<a href="?p=' . $k . '"' . ($p === $k ? ' class="on"' : '') . '>' . $v . '</a>'; echo '</nav>';
        echo '<span class="who">' . h($me['username']) . ' · <a href="?p=logout">Sign out</a></span>';
    }
    echo '</header>';
}
function page_foot(): void { echo '</body></html>'; }
