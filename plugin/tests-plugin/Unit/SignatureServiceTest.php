<?php

use AdrielPartners\WpAudioBuddy\Security\SignatureService;

class SignatureServiceTest extends \PHPUnit\Framework\TestCase
{
    public function test_request_signature_includes_timestamp_and_site_id(): void
    {
        $body = '{"ok":true}';
        $secret = 'secret';
        $timestamp = '1779897600';
        $site_id = 'site-1';

        $signature = SignatureService::sign_request($body, $secret, $timestamp, $site_id);

        $this->assertTrue(SignatureService::verify_request($body, $secret, $timestamp, $site_id, $signature));
        $this->assertFalse(SignatureService::verify_request($body, $secret, '1779897601', $site_id, $signature));
        $this->assertFalse(SignatureService::verify_request($body, $secret, $timestamp, 'site-2', $signature));
    }

    public function test_download_signature_binds_attachment_job_and_expiration(): void
    {
        $signature = SignatureService::sign_download_url(123, 'job-uuid', 1779897600, 'secret');

        $this->assertSame($signature, SignatureService::sign_download_url(123, 'job-uuid', 1779897600, 'secret'));
        $this->assertNotSame($signature, SignatureService::sign_download_url(124, 'job-uuid', 1779897600, 'secret'));
    }
}
