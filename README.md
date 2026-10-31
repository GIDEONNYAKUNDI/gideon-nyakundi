# Nyakundi for Nyamira 2027

Campaign website for Dr. Gideon Moseti Nyakundi, candidate for Governor of Nyamira County, Kenya (2027).

The site is a single static page (`index.html`) written in plain HTML, CSS and JavaScript, with no framework and no build step. Fonts load from Google Fonts.

## Sections
About Dr. Gideon, Manifesto (Why Gideon, Nyamira Today, The Challenges, the 12 Pillars of Change, First 100 Days, Governor's Scorecard), The New Nyamira, the four constituencies and 20 wards, Get Involved (change agents, volunteer sign-up, polling station agents traced County > Constituency > Ward > Polling station > Stream, campaign trail, FAQ), Contact and Donate (pledges).

## Run it
Open `index.html` in a browser, or publish it with GitHub Pages (Settings > Pages > Deploy from branch > `main` / root).

## Forms
On the Claude-hosted version, sign-ups are saved through Claude's artifact database. Outside Claude that database is not available, so the forms ask visitors to send their details by SMS or WhatsApp to the campaign line. To collect sign-ups on your own host, connect the `save()` function in the script to a form service or database (for example Firebase, Supabase, Formspree or Google Sheets).

## Polling stations
Station names per ward are loaded into the `stations` store from the IEBC register. To hard-code them for a static host, fill the `stations` object in the script with entries like `{"rigoma": [{"name": "Rigoma Primary School", "streams": 3}]}`.

## Back office (polling station agents, volunteers, pledges)

The website forms save to a MySQL database on the cPanel hosting, and the team works from a
password-protected back office at `https://kucaparty.com/backoffice/`.

**One-time setup**

1. cPanel → **MySQL Databases**: create a database (e.g. `kucapart_nyakundi`), create a user with a strong password, and add the user to the database with **All Privileges**.
2. Deploy this repository as usual (cPanel → Git Version Control → Update from Remote → Deploy HEAD Commit).
3. cPanel → **File Manager** → `public_html/backoffice/`: copy `config.sample.php` to `config.php` and fill in the database name, user and password, and a long random `secret`. `config.php` is never committed to GitHub.
4. Open `https://kucaparty.com/backoffice/` and create the first administrator account. The tables are created automatically.
5. In the back office, **Team** → add constituency coordinators. Each coordinator sees only their own constituency.

Requires PHP 7.4 or newer (PHP 8 recommended: cPanel → MultiPHP Manager).

**SMS (HostPinnacle) and e-mail (SMTP)**

Everyone who signs up gets a confirmation SMS, chosen phones can get an SMS alert, and the team gets an e-mail for every sign-up. Administrators see every SMS and e-mail in the back office under **SMS** and **E-mail**.

1. cPanel → **Email Accounts**: create a sender such as `noreply@kucaparty.com`. **Connect Devices** shows the SMTP host and port.
2. In `backoffice/config.php` fill in the `sms` block (HostPinnacle user ID, password, API key, approved sender ID, a random `callback_token`) and the `smtp` block (host, port, mailbox and password, who to `notify`), and set `enabled` to `true` in each. Copy the two blocks from `config.sample.php` if your `config.php` is older.
3. Back office → **E-mail** → **Send a test e-mail**. Back office → **SMS** → send yourself a test SMS.
4. While `test_mode` is `true`, HostPinnacle accepts messages but does not deliver them. Set it to `false` to go live.
5. Back office → **SMS** → **Register delivery webhook with HostPinnacle**, so each SMS changes from "sent" to "delivered" or "failed".

To text a group, filter the Agents, Volunteers or Pledges list (for example one ward, or everyone still "New") and press **Text this list**.

**Files**

- `api/save.php` receives sign-ups from the website forms (consent required, spam trap, rate limited), then sends the confirmation SMS and the team e-mail.
- `api/sms-delivery.php` receives HostPinnacle delivery reports.
- `backoffice/index.php` the back office: overview and coverage by ward, every stream with its agent or a gap, agent / volunteer / pledge lists with status and notes, CSV downloads, team accounts, SMS (send, delivery status, balance) and the e-mail log.
- `backoffice/_messaging.php` HostPinnacle SMS and SMTP e-mail.
- `nyamira-polling-stations.json` the IEBC list of centres and streams used for the coverage figures.
