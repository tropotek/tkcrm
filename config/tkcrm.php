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

];
