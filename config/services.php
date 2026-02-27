<?php

return [
    'recaptcha' => [
        'secret' => env('RECAPTCHA_SECRET'),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
    ],

    'clamav' => [
        'path' => env('CLAMAV_PATH', 'clamscan'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],
];
