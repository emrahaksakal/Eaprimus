<?php
/**
 * Eaprimus JWT Helper (HS256)
 * A lightweight, dependency-free class to handle JSON Web Tokens.
 */
class JWT
{
    public static function encode(array $payload, string $secret, int $expirySeconds = 3600): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        
        // Add expiration if not set
        if (!isset($payload['exp'])) {
            $payload['exp'] = time() + $expirySeconds;
        }
        
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function decode(string $jwt, string $secret)
    {
        $tokenParts = explode('.', $jwt);
        if (count($tokenParts) !== 3) {
            return false;
        }
        
        $header = json_decode(self::base64UrlDecode($tokenParts[0]), true);
        $payload = json_decode(self::base64UrlDecode($tokenParts[1]), true);
        $signatureProvided = $tokenParts[2];
        
        // Verify expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }
        
        // Rebuild signature to verify
        $base64UrlHeader = $tokenParts[0];
        $base64UrlPayload = $tokenParts[1];
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        if (hash_equals($base64UrlSignature, $signatureProvided)) {
            return $payload;
        }
        
        return false;
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode(string $data): string
    {
        $remain = strlen($data) % 4;
        if ($remain) {
            $data .= str_repeat('=', 4 - $remain);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
