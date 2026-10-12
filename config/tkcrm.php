<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login and recovery throttling
    |--------------------------------------------------------------------------
    |
    | An email is locked out from one IP address after this many failed logins,
    | for this many minutes. The same limit applies to how many password reset
    | links one address can ask for.
    |
    */

    'login' => [
        'max_attempts' => (int) env('TKCRM_LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('TKCRM_LOGIN_LOCKOUT_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reverse proxy
    |--------------------------------------------------------------------------
    |
    | The proxies in front of the site whose forwarded headers are believed:
    | `*` for any, or a comma list of addresses and ranges. With none, the
    | site takes every request as it arrives. Set it when a proxy ends TLS,
    | or links and redirects come out as http.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | The largest file that can be attached, in kilobytes, and the kinds of
    | file accepted, by extension. The kind is checked against the file's
    | contents, not only its name. Raising the size also needs PHP's
    | upload_max_filesize and post_max_size raised (docker/php.ini).
    |
    */

    'attachments' => [
        'max_kb' => 10240,
        'extensions' => [
            'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp',
            'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'zip',
        ],
        // The longest side of an image's thumbnail, in pixels.
        'thumbnail_px' => 240,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | A notification that has been read is deleted this many days after it
    | last changed. An unread one is kept.
    |
    */

    'notifications' => [
        'keep_read_days' => (int) env('TKCRM_NOTIFICATIONS_KEEP_READ_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | How mail is sent (the SMTP server, or the log when none is set) is in
    | config/mail.php, from the MAIL_ settings in the environment.
    |
    | redirect_to: when set, every message goes to this one address instead
    | of whoever it was for, and its subject says who that was. For trial
    | runs against real client data.
    |
    | retry_after: seconds to wait before each further attempt at a message
    | that failed to send. Three entries, so three more attempts.
    |
    */

    'mail' => [
        'redirect_to' => env('MAIL_REDIRECT_TO') ?: null,
        'retry_after' => [60, 300, 900],
    ],

    /*
    |--------------------------------------------------------------------------
    | Site monitoring
    |--------------------------------------------------------------------------
    |
    | timeout: seconds a site has to answer a check.
    |
    | down_after: how many checks in a row must fail before a site is down.
    | One success makes it up again.
    |
    | outside: addresses asked when any site fails, to tell a site that is
    | down from this machine having lost its own connection. If none of them
    | answers, the run is skipped and nothing is recorded.
    |
    | keep_ping_days: checks older than this are deleted nightly.
    |
    */

    'api' => [
        // How many requests a minute one token's user may make of the read-only API.
        'per_minute' => (int) env('TKCRM_API_PER_MINUTE', 120),
    ],

    'monitoring' => [
        'timeout' => 15,
        'down_after' => 2,
        'outside' => [
            'https://www.google.com/generate_204',
            'https://www.cloudflare.com/cdn-cgi/trace',
        ],
        'keep_ping_days' => 365,
    ],

];
