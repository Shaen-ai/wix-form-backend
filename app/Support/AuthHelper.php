<?php

namespace App\Support;

class AuthHelper
{
    /**
     * Extract the token from an Authorization header value.
     * Accepts both "Bearer <token>" and raw "<token>" formats (Wix SDK may send either).
     */
    public static function extractTokenFromAuthHeader(?string $auth): ?string
    {
        if ($auth === null || $auth === '') {
            return null;
        }

        $auth = trim($auth);
        if (preg_match('/^bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }

        return $auth;
    }
}
