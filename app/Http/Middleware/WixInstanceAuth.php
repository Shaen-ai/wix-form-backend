<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WixInstanceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization');
        Log::info('[WixInstanceAuth] Incoming request', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'has_auth_header' => !!$auth,
            'auth_header_preview' => $auth ? substr($auth, 0, 40) . '...' : null,
            'origin' => $request->header('Origin'),
            'referer' => $request->header('Referer'),
            'x_wix_comp_id' => $request->header('X-Wix-Comp-Id'),
        ]);

        if (! $auth || ! str_starts_with($auth, 'Bearer ')) {
            Log::warning('[WixInstanceAuth] Missing or invalid Authorization header', [
                'auth_header' => $auth,
                'all_headers' => collect($request->headers->all())->map(fn($v) => $v[0] ?? null)->toArray(),
            ]);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = substr($auth, 7);
        Log::info('[WixInstanceAuth] Token received', [
            'token_length' => strlen($token),
            'token_preview' => substr($token, 0, 50) . '...',
            'token_parts_count' => count(explode('.', $token)),
        ]);

        try {
            $key = config('app.jwt_secret');
            Log::info('[WixInstanceAuth] JWT config', [
                'has_jwt_secret' => !empty($key),
                'jwt_secret_length' => $key ? strlen($key) : 0,
                'environment' => app()->environment(),
            ]);

            if (empty($key)) {
                if (app()->environment('production')) {
                    Log::error('[WixInstanceAuth] JWT secret not configured in production');
                    return response()->json(['message' => 'JWT secret not configured'], 500);
                }
                Log::info('[WixInstanceAuth] No JWT secret — decoding token payload without verification (non-production)');
                $parts = explode('.', $token);
                $payload = isset($parts[1])
                    ? (json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [])
                    : [];
            } else {
                $payload = (array) JWT::decode($token, new Key($key, 'HS256'));
            }

            Log::info('[WixInstanceAuth] Token decoded', [
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::error('[WixInstanceAuth] Token decode FAILED', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 50) . '...',
            ]);
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $wixSiteId = $payload['wixSiteId'] ?? $payload['siteId'] ?? null;
        $wixInstanceId = $payload['wixInstanceId'] ?? $payload['instanceId'] ?? null;

        Log::info('[WixInstanceAuth] Extracted IDs', [
            'wixSiteId' => $wixSiteId,
            'wixInstanceId' => $wixInstanceId,
        ]);

        if (! $wixSiteId) {
            Log::warning('[WixInstanceAuth] Missing site ID in token payload', [
                'payload' => $payload,
            ]);
            return response()->json(['message' => 'Invalid token: missing site id'], 401);
        }

        $tenant = Tenant::updateOrCreate(
            ['wix_site_id' => $wixSiteId],
            ['plan' => app()->environment('local') ? 'premium' : 'free', 'wix_instance_id' => $wixInstanceId]
        );

        Log::info('[WixInstanceAuth] Tenant resolved', [
            'tenant_id' => $tenant->id,
            'wix_site_id' => $tenant->wix_site_id,
            'plan' => $tenant->plan,
        ]);

        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('wixSiteId', $wixSiteId);
        $request->attributes->set('wixInstanceId', $wixInstanceId);

        return $next($request);
    }
}
