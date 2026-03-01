<?php

return [
    'default' => env('MAIL_MAILER', 'log'),
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],
    'feature_request_recipient' => env('MAIL_FEATURE_REQUEST_RECIPIENT', 'info@nextechspires.com'),
    'mailers' => [
        'log' => [
            'transport' => 'log',
        ],
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            // Use null for no encryption (local Postfix); .env values are strings so "null" must be normalized
            'encryption' => in_array(env('MAIL_ENCRYPTION'), ['', 'null'], true) ? null : (env('MAIL_ENCRYPTION') ?? 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
        ],
        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs'),
        ],
    ],
];
