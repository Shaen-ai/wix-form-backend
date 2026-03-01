<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WixTokenInfoService
{
    private const TOKEN_INFO_URL = 'https://www.wixapis.com/oauth2/token-info';

    /**
     * Validate the token with Wix Token Info API.
     *
     * @param  string  $token  Raw token value (without "Bearer " prefix)
     * @return array{instanceId: string, wixSiteId: ?string}|null
     */
    public function getTokenInfo(string $token): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(self::TOKEN_INFO_URL, [
                    'token' => $token,
                ]);

            if (! $response->successful()) {
                Log::debug('[WixTokenInfo] API returned non-200', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();
            $instanceId = $data['instanceId'] ?? $data['instance_id'] ?? null;

            if (! $instanceId) {
                Log::debug('[WixTokenInfo] Response has no instanceId', ['keys' => array_keys($data ?? [])]);

                return null;
            }

            $wixSiteId = $data['siteId'] ?? $data['wixSiteId'] ?? $data['site_id'] ?? null;

            return [
                'instanceId'  => (string) $instanceId,
                'wixSiteId'   => $wixSiteId ? (string) $wixSiteId : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[WixTokenInfo] Request failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
