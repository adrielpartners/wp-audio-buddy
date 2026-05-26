<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Security;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * HMAC request signing for WordPress <-> worker communication.
 *
 * Both outgoing dispatch requests and incoming callbacks use the same
 * signing scheme: SHA-256 HMAC over the JSON body with the shared secret.
 */
final class SignatureService
{
    /**
     * Sign a raw body payload with the shared secret.
     */
    public static function sign(string $raw_body, string $secret): string
    {
        return hash_hmac('sha256', $raw_body, $secret);
    }

    /**
     * Verify a signature against the expected value.
     *
     * Handles both bare hex signatures and "sha256=" prefixed signatures
     * (the worker may send the prefix).
     */
    public static function verify(string $raw_body, string $secret, string $provided_signature): bool
    {
        $expected = self::sign($raw_body, $secret);
        $provided = str_starts_with($provided_signature, 'sha256=')
            ? substr($provided_signature, 7)
            : $provided_signature;

        return hash_equals($expected, $provided);
    }
}