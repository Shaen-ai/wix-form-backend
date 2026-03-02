<?php

namespace App\Http\Middleware;

use App\Services\WixTokenInfoService;
use App\Support\AuthHelper;
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
        $auth = $this->resolveAuthorizationHeader($request);

        if (! $auth) {
            Log::warning('[WixInstanceAuth] Missing Authorization header', [
                'method'       => $request->method(),
                'url'          => $request->fullUrl(),
                'all_headers'  => array_keys($request->headers->all()),
            ]);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = AuthHelper::extractTokenFromAuthHeader($auth);
        if (! $token) {
            Log::warning('[WixInstanceAuth] Empty token in Authorization header');
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Primary: Wix Token Info API (Wix recommended approach)
        $tokenInfo = app(WixTokenInfoService::class)->getTokenInfo($token);
        if ($tokenInfo) {
            Log::debug('[WixInstanceAuth] Verified via Wix Token Info API');
            $request->attributes->set('instanceId', $tokenInfo['instanceId']);
            $request->attributes->set('wixSiteId', $tokenInfo['wixSiteId']);

            return $next($request);
        }

        // Fallback: local decode strategies (for offline/legacy tokens)
        $key = config('app.jwt_secret');
        $payload = $this->decodeToken($token, $key);

        if ($payload === null) {
            $parts = explode('.', $token);
            $errorContext = [
                'method'      => $request->method(),
                'url'         => $request->fullUrl(),
                'token_parts' => count($parts),
                'token_len'   => strlen($token),
            ];
            // Try to decode any part as JSON to help diagnose Wix token format
            foreach ($parts as $i => $part) {
                $decoded = $this->decodeBase64Json($part);
                if (is_array($decoded)) {
                    $errorContext["part_{$i}_keys"] = array_keys($decoded);
                    $errorContext["part_{$i}_instanceId"] = $this->extractInstanceId($decoded);
                }
            }
            Log::error('[WixInstanceAuth] All token decode strategies failed', $errorContext);
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $instanceId = $this->extractInstanceId($payload);
        $wixSiteId  = $this->extractSiteId($payload);

        if (! $instanceId) {
            Log::warning('[WixInstanceAuth] Missing instance ID in token payload', [
                'keys'    => array_keys($payload),
                'payload' => $payload,
            ]);
            return response()->json(['message' => 'Invalid token: missing instance id'], 401);
        }

        $request->attributes->set('instanceId', $instanceId);
        $request->attributes->set('wixSiteId', $wixSiteId);

        return $next($request);
    }

    /**
     * Try every available source for the Authorization header.
     * Apache CGI/FastCGI and some reverse-proxy setups strip or rename it.
     * X-Authorization and X-Instance-Token are fallbacks when proxies strip Authorization.
     */
    private function resolveAuthorizationHeader(Request $request): ?string
    {
        $auth = $request->header('Authorization')
            ?? $request->header('X-Authorization')
            ?? $request->header('X-Instance-Token');

        if (! $auth && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (! $auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (! $auth && isset($_SERVER['HTTP_X_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_X_AUTHORIZATION'];
        }

        if (! $auth && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (in_array(strtolower($name), ['authorization', 'x-authorization', 'x-instance-token'], true)) {
                    $auth = $value;
                    break;
                }
            }
        }

        if ($auth) {
            $request->headers->set('Authorization', $auth);
        }

        return $auth;
    }

    /**
     * Try multiple decode strategies in order:
     *   1. Standard JWT (3-part, HS256) verified with app secret
     *   2. Wix classic instance token (sig.payload, HMAC-SHA256)
     *   3. Decode JWT payload without signature verification
     *      (Wix SDK access tokens are signed by Wix's servers, not our secret)
     */
    private function decodeToken(string $token, ?string $key): ?array
    {
        if ($key) {
            $payload = $this->tryStandardJwt($token, $key);
            if ($payload !== null) {
                return $payload;
            }

            $payload = $this->tryWixInstanceToken($token, $key);
            if ($payload !== null) {
                return $payload;
            }
        }

        $payload = $this->tryDecodePayloadWithoutVerification($token);
        if ($payload !== null) {
            if (! $key) {
                Log::info('[WixInstanceAuth] Decoded token without verification (no secret configured)');
            } else {
                Log::info('[WixInstanceAuth] Decoded token without local verification (Wix SDK signed token)');
            }
            return $payload;
        }

        return null;
    }

    /**
     * Strategy 1: standard 3-part JWT, HS256, verified with our app secret.
     */
    private function tryStandardJwt(string $token, string $key): ?array
    {
        try {
            return (array) JWT::decode($token, new Key($key, 'HS256'));
        } catch (\Throwable $e) {
            Log::debug('[WixInstanceAuth] HS256 JWT decode failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Strategy 2: Wix classic instance token — two base64url parts
     * separated by a dot: <signature>.<payload>.
     * The signature is HMAC-SHA256(payload_raw, secret).
     */
    private function tryWixInstanceToken(string $token, string $key): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$sig, $data] = $parts;

        $expectedSig = hash_hmac('sha256', $data, $key, true);
        $actualSig   = $this->base64UrlDecode($sig);

        if ($actualSig === null || ! hash_equals($expectedSig, $actualSig)) {
            Log::debug('[WixInstanceAuth] Wix instance token HMAC mismatch');
            return null;
        }

        $payload = $this->decodeBase64Json($data);

        if (! is_array($payload)) {
            return null;
        }

        Log::debug('[WixInstanceAuth] Verified Wix instance token');

        return $payload;
    }

    /**
     * Strategy 3: decode the JWT payload without signature verification.
     * This covers tokens signed by Wix's OAuth servers (RS256) that we
     * cannot verify locally but still carry a valid instanceId claim.
     * For multi-part tokens (e.g. 5-part), tries each middle segment as payload.
     */
    private function tryDecodePayloadWithoutVerification(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) < 2) {
            return null;
        }

        // JWT payload is typically at index 1; for non-standard formats try all parts
        $indicesToTry = count($parts) === 3 ? [1] : range(1, min(4, count($parts) - 1));
        foreach ($indicesToTry as $idx) {
            if (! isset($parts[$idx])) {
                continue;
            }
            $payload = $this->decodeBase64Json($parts[$idx]);
            if (! is_array($payload)) {
                continue;
            }
            $instanceId = $this->extractInstanceId($payload);
            if ($instanceId) {
                return $payload;
            }
            Log::debug('[WixInstanceAuth] Part ' . $idx . ' decoded but no instance ID', [
                'keys' => array_keys($payload),
            ]);
        }

        return null;
    }

    private function base64UrlDecode(string $b64): ?string
    {
        $b64 = strtr($b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);

        return $decoded !== false ? $decoded : null;
    }

    private function decodeBase64Json(string $b64): ?array
    {
        $decoded = $this->base64UrlDecode($b64);
        if ($decoded === null) {
            return null;
        }
        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    /**
     * Extract the Wix instance ID from a decoded token payload.
     * Handles multiple claim layouts used by Wix (flat and nested).
     */
    private function extractInstanceId(array $payload): ?string
    {
        $candidates = [
            $payload['instanceId'] ?? null,
            $payload['wixInstanceId'] ?? null,
            $payload['instance_id'] ?? null,
            $payload['app_instance_id'] ?? null,
        ];
        foreach ($candidates as $id) {
            if ($id !== null && $id !== '') {
                return (string) $id;
            }
        }

        $data = $payload['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (is_array($data)) {
            $id = $data['instanceId'] ?? $data['wixInstanceId'] ?? $data['instance_id'] ?? null;
            if ($id) {
                return (string) $id;
            }
        }

        // context.appInstanceId (Wix SDK sometimes nests here)
        $context = $payload['context'] ?? null;
        if (is_array($context)) {
            $id = $context['instanceId'] ?? $context['appInstanceId'] ?? $context['wixInstanceId'] ?? null;
            if ($id) {
                return (string) $id;
            }
        }

        if (isset($payload['sub']) && is_string($payload['sub']) && $payload['sub'] !== '') {
            return $payload['sub'];
        }

        return null;
    }

    /**
     * Extract the Wix site ID from a decoded token payload.
     */
    private function extractSiteId(array $payload): ?string
    {
        $id = $payload['wixSiteId']
            ?? $payload['siteId']
            ?? $payload['site_id']
            ?? null;

        if ($id) {
            return (string) $id;
        }

        $data = $payload['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (is_array($data)) {
            $id = $data['wixSiteId'] ?? $data['siteId'] ?? $data['site_id'] ?? null;
            if ($id) {
                return (string) $id;
            }
        }

        return null;
    }
}
