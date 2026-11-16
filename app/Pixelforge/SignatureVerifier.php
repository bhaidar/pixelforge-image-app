<?php

namespace App\Pixelforge;

/** Verifies the `Pixelforge-Signature: t=<unix>,v1=<hex hmac>` header against the raw request body. */
class SignatureVerifier
{
    public function __construct(private readonly string $secret, private readonly int $toleranceSeconds = 300) {}

    public function verify(string $payload, ?string $header, ?int $now = null): bool
    {
        if ($this->secret === '' || ! $header) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        return hash_equals($expected, $parts['v1'] ?? '')
            && abs(($now ?? time()) - $timestamp) <= $this->toleranceSeconds;
    }
}
