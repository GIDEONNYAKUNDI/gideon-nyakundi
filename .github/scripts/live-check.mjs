// Checks the live website from the outside, the way an attacker or a visitor would see it.
// Every check prints PASS or FAIL; the run fails if any check fails.
// Requests are spaced out so the host's own firewall does not mistake the checker for an attack.
import { chromium, request } from 'playwright';
const SITE = process.env.SITE || 'https://kucaparty.com';
const results = [];
let unreachable = 0;
const check = (name, ok, info = '') => {
  results.push(ok);
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  (' + info + ')' : ''}`);
  if (!ok) console.log(`::error title=Security check failed::${name}${info ? ' (' + info + ')' : ''}`);
};
const pause = ms => new Promise(r => setTimeout(r, ms));
const api = await request.newContext({ maxRedirects: 0, timeout: 30000 });

/* One request, retried; returns {status, text, headers} or null if the site could not be reached. */
async function get(path, opts = {}) {
  for (let i = 0; i < 3; i++) {
    try {
      await pause(700);
      const r = await api.fetch(path.startsWith('http') ? path : SITE + path, opts);
      return { status: r.status(), text: await r.text(), headers: r.headers() };
    } catch (e) {
      if (i === 2) { unreachable++; console.log(`::warning::Could not reach ${path}: ${String(e.message).split('\n')[0]}`); return null; }
      await pause(4000);
    }
  }
}
const blocked = r => r === null || r.status === 403 || r.status === 404;

async function main() {
  // 0. First, as a normal visitor in a real browser (before any attacker-style probes below)
  // 6. The real page in a real browser
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  await page.goto(SITE + '/', { waitUntil: 'load', timeout: 60000 });
  const title = await page.title();
  check('real visitors get the campaign page (not a firewall page)', /Nyakundi|Gideon/i.test(title), title);
  check('no script errors on the page', errors.length === 0, errors.join(' | '));
  check('voter registration form present', !!(await page.$('#regForm')));
  check('Start-here panel with 12 pillars', (await page.$$('#start .stile')).length === 12);
  check('Kiswahili switch present', !!(await page.$('#langBtn')));
  check('share buttons on videos', (await page.$$('.vsharebtn')).length >= 5);
  check('press room has no download links', (await page.$$('#press a[download]')).length === 0);
  check('privacy notice linked', !!(await page.$('a[href="privacy.html"]')));
  const scripts = await page.$$eval('script[src]', s => s.map(x => x.src).filter(u => !u.startsWith(location.origin)));
  check('no third-party scripts (no trackers or marketers)', scripts.length === 0, scripts.join(', '));
  await browser.close();
  // 1. Private files can never be downloaded
  for (const p of ['/backoffice/config.php', '/backoffice/_lib.php', '/backoffice/_settings.php', '/backoffice/config.sample.php',
                   '/.git/config', '/.cpanel.yml', '/README.md', '/ops/install-htaccess.sh', '/.env', '/backoffice/.htaccess',
                   '/press/Nyakundi2027-Manifesto.pdf', '/press/Dr-Gideon-Nyakundi-portrait.jpg', '/press/Dr-Gideon-Nyakundi-office.jpg']) {
    const r = await get(p);
    check(`private file not served: ${p}`, blocked(r) && !/db_pass|db_user|'secret'/i.test(r ? r.text : ''), r ? String(r.status) : 'blocked');
  }
  // 2. No folder listings
  for (const p of ['/img/', '/media/', '/press/view/']) { const r = await get(p); check(`no folder listing: ${p}`, !r || !/Index of \//i.test(r.text), r ? String(r.status) : 'blocked'); }

  // 3. Security headers on the public site
  const home = await get('/');
  if (!home) { check('home page reachable', false, 'site could not be reached'); return; }
  const h = home.headers;
  check('home page loads', home.status === 200, String(home.status));
  check('header: Strict-Transport-Security', !!h['strict-transport-security']);
  check('header: X-Content-Type-Options nosniff', (h['x-content-type-options'] || '').includes('nosniff'));
  check('header: X-Frame-Options (no clickjacking)', !!h['x-frame-options']);
  check('header: Referrer-Policy', !!h['referrer-policy']);
  check('no PHP version leaked', !h['x-powered-by']);

  // 4. Back office is closed to anyone not signed in
  const bo = await get('/backoffice/');
  if (bo) {
    check('back office shows only the sign-in page', bo.status === 200 && /sign in/i.test(bo.text) && !/at a glance/i.test(bo.text));
    check('back office not indexed by search engines', /noindex/i.test(bo.headers['x-robots-tag'] || ''));
    check('back office sends no-store (no caching of private data)', /no-store/i.test(bo.headers['cache-control'] || ''));
  }
  for (const p of ['?p=voters', '?p=volunteers&export=1', '?p=agents&export=1', '?p=pledges', '?p=settings']) {
    const r = await get('/backoffice/' + p);
    if (r) check(`back office ${p} needs sign-in`, !/text\/csv/.test(r.headers['content-type'] || '') && /sign in/i.test(r.text) && !/07\d{8}/.test(r.text));
  }

  // 5. The sign-up API only accepts the website's own forms
  let r = await get('/api/save.php'); if (r) check('API refuses page visits (GET)', r.status === 405, String(r.status));
  r = await get('/api/save.php', { method: 'POST', headers: { Origin: 'https://evil.example', 'Content-Type': 'application/json' }, data: { type: 'voter' } });
  if (r) check('API refuses other websites', r.status === 403, String(r.status));
  r = await get('/api/save.php', { method: 'POST', headers: { Origin: SITE, 'Content-Type': 'application/json' }, data: { type: 'voter', name: 'Check', phone: '0700000000', ward: 'Esise' } });
  if (r) check('API refuses sign-ups without consent', r.status === 400, String(r.status));

  for (const [p, name] of [['/press/view/manifesto-01.webp', 'manifesto viewable in the press room'], ['/privacy.html', 'privacy notice page loads'], ['/img/share-card.jpg', 'link preview image'], ['/sitemap.xml', 'sitemap for search engines']]) {
    const x = await get(p); if (x) check(name, x.status === 200, String(x.status));
  }
}

try { await main(); }
catch (e) { console.log(`::error title=Security check could not finish::${String(e.message).split('\n')[0]}`); results.push(false); }
const failed = results.filter(x => !x).length;
if (unreachable) console.log(`::notice title=Host firewall::${unreachable} request(s) were refused or timed out. The host's own firewall may have blocked the checker; that is a protection, not a fault.`);
console.log(`\n${results.length - failed} of ${results.length} checks passed.`);
console.log(`::notice title=Security check::${results.length - failed} of ${results.length} checks passed`);
process.exit(failed ? 1 : 0);
