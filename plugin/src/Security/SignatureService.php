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

    public static function sign_request(string $raw_body, string $secret, string $timestamp, string $site_id): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $site_id . "\n" . $raw_body, $secret);
    }

    public static function sign_download_url(int $attachment_id, string $job_uuid, int $expires, string $secret): string
    {
        return hash_hmac('sha256', "GET\n{$attachment_id}\n{$job_uuid}\n{$expires}", $secret);
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

    public static function verify_request(string $raw_body, string $secret, string $timestamp, string $site_id, string $provided_signature): bool
    {
        $expected = self::sign_request($raw_body, $secret, $timestamp, $site_id);
        $provided = str_starts_with($provided_signature, 'sha256=')
            ? substr($provided_signature, 7)
            : $provided_signature;

        return hash_equals($expected, $provided);
    }
}
