<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RecaptchaService
{
    public function verify(string $token, ?string $ip = null, string $expectedAction = 'submit'): bool
    {
        $projectId = config('services.recaptcha.project_id');
        $apiKey = config('services.recaptcha.api_key');
        $siteKey = config('services.recaptcha.site_key');

        if ($projectId && $apiKey) {
            return $this->verifyEnterprise($token, $expectedAction, $projectId, $apiKey, $siteKey);
        }

        $secret = config('services.recaptcha.secret');
        if (empty($secret)) {
            if (app()->environment('production')) {
                return false;
            }
            return true;
        }

        return $this->verifyLegacy($token, $secret, $ip);
    }

    private function verifyEnterprise(string $token, string $expectedAction, string $projectId, string $apiKey, ?string $siteKey): bool
    {
        $url = "https://recaptchaenterprise.googleapis.com/v1/projects/{$projectId}/assessments?key={$apiKey}";

        $payload = [
            'event' => [
                'token' => $token,
                'expectedAction' => $expectedAction,
            ],
        ];
        if ($siteKey) {
            $payload['event']['siteKey'] = $siteKey;
        }

        $response = Http::asJson()->post($url, $payload);

        if (! $response->successful()) {
            return false;
        }

        $body = $response->json();
        $tokenProperties = $body['tokenProperties'] ?? [];
        if (($tokenProperties['valid'] ?? false) === false) {
            return false;
        }
        $riskAnalysis = $body['riskAnalysis'] ?? [];
        $score = $riskAnalysis['score'] ?? 0;

        return $score >= 0.5;
    }

    private function verifyLegacy(string $token, string $secret, ?string $ip): bool
    {
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        $body = $response->json();
        return ($body['success'] ?? false) === true;
    }
}
