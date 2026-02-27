<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WixInstanceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization');
        if (! $auth || ! str_starts_with($auth, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = substr($auth, 7);

        try {
            $key = config('app.jwt_secret');
            if (empty($key)) {
                if (app()->environment('production')) {
                    return response()->json(['message' => 'JWT secret not configured'], 500);
                }
                $parts = explode('.', $token);
                $payload = isset($parts[1])
                    ? (json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [])
                    : [];
            } else {
                $payload = (array) JWT::decode($token, new Key($key, 'HS256'));
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $wixSiteId = $payload['wixSiteId'] ?? $payload['siteId'] ?? null;
        $wixInstanceId = $payload['wixInstanceId'] ?? $payload['instanceId'] ?? null;

        if (! $wixSiteId) {
            return response()->json(['message' => 'Invalid token: missing site id'], 401);
        }

        $tenant = Tenant::updateOrCreate(
            ['wix_site_id' => $wixSiteId],
            ['plan' => app()->environment('local') ? 'premium' : 'free', 'wix_instance_id' => $wixInstanceId]
        );

        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('wixSiteId', $wixSiteId);
        $request->attributes->set('wixInstanceId', $wixInstanceId);

        return $next($request);
    }
}
