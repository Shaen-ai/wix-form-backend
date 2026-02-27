<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RecaptchaService
{
    public function verify(string $token, ?string $ip = null): bool
    {
        $secret = config('services.recaptcha.secret');
        if (empty($secret)) {
            if (app()->environment('production')) {
                return false;
            }
            return true;
        }

        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        $body = $response->json();
        return ($body['success'] ?? false) === true;
    }
}
