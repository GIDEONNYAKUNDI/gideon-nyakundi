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

    // Optional. SMS, the confirmation messages and e-mail are normally set up in the back office
    // under Settings, which overrides everything below. Keep the secret above unchanged afterwards:
    // it encrypts the passwords saved there.
    // SMS through HostPinnacle (https://smsportal.hostpinnacle.co.ke).
    'sms' => [
        'enabled'   => false,       // true once the details below are filled in
        'test_mode' => true,        // true: HostPinnacle accepts messages but does not deliver them. false to go live.
        'userid'    => 'PUT-HOSTPINNACLE-USER-ID-HERE',
        'password'  => 'PUT-HOSTPINNACLE-PASSWORD-HERE',
        'api_key'   => 'PUT-HOSTPINNACLE-API-KEY-HERE',
        'sender_id' => 'PUT-APPROVED-SENDER-ID-HERE',   // e.g. NYAKUNDI
        // Any long random text. Protects the delivery-report link so only HostPinnacle can use it.
        'callback_token' => 'CHANGE-ME-to-another-long-random-text',
        // Confirmation texted to each person who signs up. Set one to '' to send nothing for that form.
        'templates' => [
            'volunteer' => 'Thank you {name} for joining Team Nyakundi 2027. Your {ward} Ward coordinator will call you soon. Omonto, Nabanto!',
            'agent'     => 'Thank you {name}. You are registered as an agent for {station}, Stream {stream}, {ward} Ward. We will call you about training.',
            'pledge'    => 'Thank you {name} for your pledge of Ksh {amount}. The finance team will call you with the official payment details. Do not send money to individuals.',
        ],
        // Phones that get a short SMS for every new sign-up, e.g. ['0723636511']. Optional.
        'alert_numbers' => [],
    ],

    // E-mail through SMTP. From cPanel > Email Accounts > Connect Devices.
    'smtp' => [
        'enabled'    => false,      // true once the details below are filled in
        'host'       => 'mail.kucaparty.com',
        'port'       => 465,
        'secure'     => 'ssl',      // 'ssl' for port 465, 'tls' for port 587
        'user'       => 'noreply@kucaparty.com',
        'pass'       => 'PUT-THE-EMAIL-PASSWORD-HERE',
        'from_email' => 'noreply@kucaparty.com',
        'from_name'  => 'Nyakundi 2027 Website',
        // Who gets an e-mail for every new sign-up.
        'notify'     => ['PUT-YOUR-EMAIL-HERE'],
    ],
];
