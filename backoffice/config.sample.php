<?php
/*
 * Nyakundi 2027 back office: settings
 *
 * In cPanel File Manager, copy this file to "config.php" in the same folder
 * (public_html/backoffice/config.php) and fill in your MySQL details.
 * config.php is never stored on GitHub, so your password stays on the server.
 */
return [
    // From cPanel > MySQL Databases. cPanel adds your account prefix, e.g. kucapart_agents
    'db_host' => 'localhost',
    'db_name' => 'kucapart_nyakundi',
    'db_user' => 'kucapart_nyakundi',
    'db_pass' => 'PUT-THE-DATABASE-PASSWORD-HERE',

    // Any long random text. Used to protect logins. Change it once, then leave it.
    'secret'  => 'CHANGE-ME-to-a-long-random-sentence-only-you-know',
];
