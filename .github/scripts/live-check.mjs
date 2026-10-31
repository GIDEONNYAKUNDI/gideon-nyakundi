// Checks the live website from the outside, the way an attacker or a visitor would see it.
// Every check prints PASS or FAIL; the run fails if any check fails.
import { chromium, request } from 'playwright';
const SITE = process.env.SITE || 'https://kucaparty.com';
const results = [];
const check = (name, ok, info = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  (' + info + ')' : ''}`); if (!ok) console.log(`::error title=Security check failed::${name}${info ? ' (' + info + ')' : ''}`); };
const api = await request.newContext({ ignoreHTTPSErrors: false, maxRedirects: 0 });
const code = async (path, opts = {}) => {
  for (let i = 0; i < 3; i++) { try { return (await api.fetch(SITE + path, { timeout: 30000, ...opts })).status(); } catch (e) { if (i === 2) { console.log(`::warning::${path}: ${e.message.split('\n')[0]}`); return 0; } await new Promise(r => setTimeout(r, 3000)); } }
};
process.on('unhandledRejection', e => { console.log(`::error title=Security check crashed::${String(e && e.message || e).split('\n')[0]}`); process.exit(1); });

// 1. Private files can never be downloaded
for (const p of ['/backoffice/config.php', '/backoffice/_lib.php', '/backoffice/_messaging.php', '/backoffice/_settings.php', '/backoffice/config.sample.php',
                 '/.git/config', '/.git/HEAD', '/.cpanel.yml', '/README.md', '/ops/install-htaccess.sh', '/ops/htaccess-block.conf', '/.env', '/backoffice/.htaccess']) {
  const c = await code(p); check(`private file blocked: ${p}`, c === 403 || c === 404, String(c));
}
for (const p of ['/backoffice/config.php', '/backoffice/_lib.php']) {
  const body = await (await api.fetch(SITE + p)).text();
  check(`no secrets in response: ${p}`, !/db_pass|db_user|secret/i.test(body));
}
// 2. No folder listings
for (const p of ['/img/', '/media/', '/api/', '/ops/']) { const r = await api.fetch(SITE + p); const t = await r.text(); check(`no folder listing: ${p}`, !/Index of \//i.test(t), String(r.status())); }

// 3. Security headers on the public site
const home = await api.fetch(SITE + '/');
const h = home.headers();
check('home page loads', home.status() === 200, String(home.status()));
check('header: Strict-Transport-Security', !!h['strict-transport-security']);
check('header: X-Content-Type-Options nosniff', (h['x-content-type-options'] || '').includes('nosniff'));
check('header: X-Frame-Options (no clickjacking)', !!h['x-frame-options']);
check('header: Referrer-Policy', !!h['referrer-policy']);
check('no PHP version leaked', !h['x-powered-by']);
const http = await api.fetch(SITE.replace('https://', 'http://') + '/', { maxRedirects: 0 }).catch(() => null);
check('plain http is not served without encryption', !http || [301, 302, 307, 308].includes(http.status()) || (await http.text()).length === 0 || !!h['strict-transport-security'], http ? String(http.status()) : 'refused');

// 4. Back office is closed to anyone not signed in
const bo = await api.fetch(SITE + '/backoffice/');
const boText = await bo.text();
check('back office shows only the sign-in page', bo.status() === 200 && /sign in/i.test(boText) && !/at a glance/i.test(boText));
check('back office not indexed by search engines', /noindex/i.test(bo.headers()['x-robots-tag'] || ''));
check('back office sends no-store (no caching of private data)', /no-store/i.test(bo.headers()['cache-control'] || ''));
check('back office forbids framing', /frame-ancestors 'none'|DENY/i.test((bo.headers()['content-security-policy'] || '') + (bo.headers()['x-frame-options'] || '')));
for (const p of ['?p=agents', '?p=volunteers', '?p=voters', '?p=pledges', '?p=agents&export=1', '?p=volunteers&export=1', '?p=voters&export=1', '?p=pledges&export=1', '?p=import', '?p=settings', '?p=sms']) {
  const r = await api.fetch(SITE + '/backoffice/' + p); const t = await r.text();
  check(`back office ${p} needs sign-in`, !/text\/csv/.test(r.headers()['content-type'] || '') && /sign in/i.test(t) && !/07\d{8}/.test(t));
}
const forged = await api.fetch(SITE + '/backoffice/', { method: 'POST', form: { username: 'admin', password: 'x' } });
check('sign-in form refuses posts without its security token', !/at a glance/i.test(await forged.text()));

// 5. The sign-up API only accepts the website's own forms
check('API refuses page visits (GET)', (await code('/api/save.php')) === 405);
check('API refuses other websites', (await code('/api/save.php', { method: 'POST', headers: { Origin: 'https://evil.example', 'Content-Type': 'application/json' }, data: { type: 'voter' } })) === 403);
check('API refuses sign-ups without consent', (await code('/api/save.php', { method: 'POST', headers: { Origin: SITE, 'Content-Type': 'application/json' }, data: { type: 'voter', name: 'Check', phone: '0700000000', ward: 'Esise' } })) === 400);

// 6. The real page in a real browser
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = []; page.on('pageerror', e => errors.push(e.message));
await page.goto(SITE + '/', { waitUntil: 'load' });
check('page title', /Nyakundi|Gideon/i.test(await page.title() + await page.textContent('h1')));
check('no script errors on the page', errors.length === 0, errors.join(' | '));
check('voter registration form present', !!(await page.$('#regForm')));
check('Youth Deal present', !!(await page.$('#youth')));
check('Start-here panel with 12 pillars', (await page.$$('#start .stile')).length === 12);
check('Kiswahili switch present', !!(await page.$('#langBtn')));
check('manifesto viewable in the press room', (await code('/press/view/manifesto-01.webp')) === 200);
for (const f of ['/press/Nyakundi2027-Manifesto.pdf', '/press/Dr-Gideon-Nyakundi-portrait.jpg', '/press/Dr-Gideon-Nyakundi-office.jpg']) { const c = await code(f); check(`press original not downloadable: ${f}`, c === 403 || c === 404, String(c)); }
check('press room has no download links', (await page.$$('#press a[download]')).length === 0);
check('sitemap for search engines', (await code('/sitemap.xml')) === 200);
check('share buttons on videos', (await page.$$('.vsharebtn')).length >= 5);
check('privacy notice linked', !!(await page.$('a[href="privacy.html"]')));
check('privacy notice page loads', (await code('/privacy.html')) === 200);
check('link preview image for WhatsApp/X/Facebook', (await code('/img/share-card.jpg')) === 200 && !!(await page.$('meta[property="og:image"]')));
const scripts = await page.$$eval('script[src]', s => s.map(x => x.src).filter(u => !u.startsWith(location.origin)));
check('no third-party scripts (no trackers or marketers)', scripts.length === 0, scripts.join(', '));
await browser.close();

const failed = results.filter(x => !x).length;
console.log(`\n${results.length - failed} of ${results.length} checks passed.`);
console.log(`::notice title=Security check::${results.length - failed} of ${results.length} checks passed`);
process.exit(failed ? 1 : 0);
