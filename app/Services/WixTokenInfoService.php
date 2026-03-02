<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WixTokenInfoService
{
    private const INSTANCE_API_URL  = 'https://www.wixapis.com/apps/v1/instance';
    private const TOKEN_INFO_URL    = 'https://www.wixapis.com/oauth2/token-info';

    /**
     * Fetch the full Wix app instance info using the Authorization token.
     *
     * Primary:  GET /apps/v1/instance   — returns instanceId, billing/plan, site info.
     * Fallback: POST /oauth2/token-info — returns only instanceId (no plan info).
     *
     * @param  string  $token  Raw token value (without "Bearer " prefix)
     * @return array{instanceId: string, wixSiteId: ?string, vendorProductId: ?string, isFree: bool, raw: array}|null
     */
    public function getTokenInfo(string $token): ?array
    {
        // ── Primary: Apps Instance API ───────────────────────────────────────────
        $result = $this->fetchInstanceApi($token);
        if ($result !== null) {
            return $result;
        }

        // ── Fallback: Token Info API (validation only, no plan data) ────────────
        return $this->fetchTokenInfoApi($token);
    }

    private function fetchInstanceApi(string $token): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json',
                ])
                ->get(self::INSTANCE_API_URL);

            if (! $response->successful()) {
                Log::debug('[WixTokenInfo] Instance API returned non-200', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $data       = $response->json() ?? [];
            $instance   = $data['instance'] ?? [];
            $site       = $data['site'] ?? [];

            $instanceId = $instance['instanceId'] ?? null;
            if (! $instanceId) {
                Log::debug('[WixTokenInfo] Instance API: no instanceId in response', [
                    'keys' => array_keys($instance),
                ]);
                return null;
            }

            $billing         = $instance['billing'] ?? null;
            $isFree          = (bool) ($instance['isFree'] ?? true);
            $vendorProductId = ($billing && ! $isFree)
                ? ($billing['packageName'] ?? null)
                : null;

            $wixSiteId = $site['siteId'] ?? null;

            Log::debug('[WixTokenInfo] Instance API validated', [
                'instanceId'      => $instanceId,
                'isFree'          => $isFree,
                'vendorProductId' => $vendorProductId,
            ]);

            return [
                'instanceId'      => (string) $instanceId,
                'wixSiteId'       => $wixSiteId ? (string) $wixSiteId : null,
                'vendorProductId' => $vendorProductId ? (string) $vendorProductId : null,
                'isFree'          => $isFree,
                'raw'             => $data,
            ];
        } catch (\Throwable $e) {
            Log::warning('[WixTokenInfo] Instance API request failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fetchTokenInfoApi(string $token): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(self::TOKEN_INFO_URL, ['token' => $token]);

            if (! $response->successful()) {
                Log::debug('[WixTokenInfo] Token Info API returned non-200', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $data       = $response->json() ?? [];
            $instanceId = $data['instanceId'] ?? $data['instance_id'] ?? null;

            if (! $instanceId) {
                Log::debug('[WixTokenInfo] Token Info API: no instanceId', [
                    'keys' => array_keys($data),
                ]);
                return null;
            }

            $wixSiteId       = $data['siteId'] ?? $data['wixSiteId'] ?? $data['site_id'] ?? null;
            $vendorProductId = $data['vendorProductId'] ?? $data['vendor_product_id'] ?? null;

            Log::debug('[WixTokenInfo] Token Info API validated (fallback)', [
                'instanceId'      => $instanceId,
                'vendorProductId' => $vendorProductId,
                'keys'            => array_keys($data),
            ]);

            return [
                'instanceId'      => (string) $instanceId,
                'wixSiteId'       => $wixSiteId ? (string) $wixSiteId : null,
                'vendorProductId' => $vendorProductId ? (string) $vendorProductId : null,
                'isFree'          => empty($vendorProductId),
                'raw'             => $data,
            ];
        } catch (\Throwable $e) {
            Log::warning('[WixTokenInfo] Token Info API request failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
