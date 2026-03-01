<?php

return [
    'smartFormAppId'        => env('SMART_FORM_APP_ID', 'ee0933f5-3cf3-4ee0-849a-3c6108fb32f5'),
    'smartFormAppSecret'    => env('SMART_FORM_APP_SECRET', 'e6e7ef14-7510-487e-bb96-bb433eddad12'),
    'wixSmartFormPublicKey' => '<<<EOT
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAoe2YJ5qmzgHNhJ0CJ+em
57gPAEIMTWKl6z9QPwGG+62viaH/Ip6dkLpjTLTAsRymE+q30FvqDg9ueweJwckm
AwEpdt4Iwa8kV8EHLGhQjz7za1XXgzC8GMuoQZ7ooDYDlxQYp5Kp40IqHTW6F1ZR
F7S+ClFDsfmVJJwG3Gj2I6KUGr4MQBY9zTmckH2UrSuhVZ5cOtHmA+LFKN8MMV9n
u/jKqjm5lxHE0WLf6erA98pbQEECS8w5o6+sOlTk1NE2HVp2u6A+mKLMT67JfoyK
zuysbFZo8t0cGZrBasQREHFQEXC2e+H4quZhxtvsUf6zy3c3hs8/eebtPZ1nnerh
/QIDAQAB
-----END PUBLIC KEY-----
EOT',

    'baseURL' => env('APP_URL', 'http://localhost:4004') . '/api/',
];
