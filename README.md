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
