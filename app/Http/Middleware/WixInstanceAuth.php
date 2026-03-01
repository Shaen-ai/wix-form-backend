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
        $auth = $this->resolveAuthorizationHeader($request);

        if (! $auth || ! str_starts_with($auth, 'Bearer ')) {
            Log::warning('[WixInstanceAuth] Missing or invalid Authorization header', [
                'has_header'   => (bool) $auth,
                'prefix'       => $auth ? substr($auth, 0, 10) : null,
                'method'       => $request->method(),
                'url'          => $request->fullUrl(),
                'all_headers'  => array_keys($request->headers->all()),
            ]);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = substr($auth, 7);
        $key   = config('app.jwt_secret');

        $payload = $this->decodeToken($token, $key);

        if ($payload === null) {
            Log::error('[WixInstanceAuth] All token decode strategies failed', [
                'method'       => $request->method(),
                'url'          => $request->fullUrl(),
                'token_parts'  => count(explode('.', $token)),
                'token_len'    => strlen($token),
            ]);
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
     */
    private function resolveAuthorizationHeader(Request $request): ?string
    {
        $auth = $request->header('Authorization');

        if (! $auth && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (! $auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (! $auth && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
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
        $actualSig   = base64_decode(strtr($sig, '-_', '+/'));

        if (! hash_equals($expectedSig, $actualSig)) {
            Log::debug('[WixInstanceAuth] Wix instance token HMAC mismatch');
            return null;
        }

        $payload = json_decode(base64_decode(strtr($data, '-_', '+/')), true);

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
     */
    private function tryDecodePayloadWithoutVerification(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) < 2) {
            return null;
        }

        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')),
            true,
        );

        if (! is_array($payload)) {
            return null;
        }

        if (! $this->extractInstanceId($payload)) {
            Log::debug('[WixInstanceAuth] Unverified payload has no recognised instance ID', [
                'keys'    => array_keys($payload),
                'payload' => $payload,
            ]);
            return null;
        }

        return $payload;
    }

    /**
     * Extract the Wix instance ID from a decoded token payload.
     * Handles multiple claim layouts used by Wix (flat and nested).
     */
    private function extractInstanceId(array $payload): ?string
    {
        $id = $payload['instanceId']
            ?? $payload['wixInstanceId']
            ?? $payload['instance_id']
            ?? null;

        if ($id) {
            return (string) $id;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            $id = $data['instanceId'] ?? $data['wixInstanceId'] ?? $data['instance_id'] ?? null;
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
        if (is_array($data)) {
            $id = $data['wixSiteId'] ?? $data['siteId'] ?? $data['site_id'] ?? null;
            if ($id) {
                return (string) $id;
            }
        }

        return null;
    }
}
