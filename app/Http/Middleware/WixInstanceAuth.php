<?php

namespace App\Http\Middleware;

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

        // Apache CGI/FastCGI may strip Authorization; fall back to server env
        if (! $auth && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
            $request->headers->set('Authorization', $auth);
        }
        if (! $auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            $request->headers->set('Authorization', $auth);
        }

        if (! $auth || ! str_starts_with($auth, 'Bearer ')) {
            Log::warning('[WixInstanceAuth] Missing or invalid Authorization header', [
                'has_header' => (bool) $auth,
                'method' => $request->method(),
                'url' => $request->fullUrl(),
            ]);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = substr($auth, 7);

        try {
            $key = config('app.jwt_secret');

            if (empty($key)) {
                if (app()->environment('production')) {
                    Log::error('[WixInstanceAuth] JWT secret not configured in production');
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
            Log::error('[WixInstanceAuth] Token decode FAILED', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $instanceId = $payload['wixInstanceId'] ?? $payload['instanceId'] ?? null;
        $wixSiteId = $payload['wixSiteId'] ?? $payload['siteId'] ?? null;

        if (! $instanceId) {
            Log::warning('[WixInstanceAuth] Missing instance ID in token payload');
            return response()->json(['message' => 'Invalid token: missing instance id'], 401);
        }

        $request->attributes->set('instanceId', $instanceId);
        $request->attributes->set('wixSiteId', $wixSiteId);

        return $next($request);
    }
}
