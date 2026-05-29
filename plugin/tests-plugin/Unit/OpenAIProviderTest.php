<?php
/**
 * Unit tests for OpenAIProvider error classification.
 *
 * Run with: phpunit tests/Unit/OpenAIProviderTest.php
 */

use AdrielPartners\WpAudioBuddy\Integrations\Providers\OpenAIProvider;

class OpenAIProviderTest extends \PHPUnit\Framework\TestCase
{
    public function test_transient_error_detection_returns_true_for_rate_limit(): void
    {
        $error = new WP_Error(OpenAIProvider::ERROR_OPENAI_RATE_LIMIT, 'Rate limited');
        $this->assertTrue(OpenAIProvider::is_transient_error($error));
    }

    public function test_transient_error_detection_supports_generic_provider_codes(): void
    {
        $this->assertTrue(OpenAIProvider::is_transient_error(new WP_Error('RATE_LIMITED', 'Rate limited')));
        $this->assertTrue(OpenAIProvider::is_transient_error(new WP_Error('SERVER_ERROR', 'Server error')));
        $this->assertTrue(OpenAIProvider::is_transient_error(new WP_Error('NETWORK_ERROR', 'Network error')));
    }

    public function test_transient_error_detection_returns_false_for_auth_failure(): void
    {
        $error = new WP_Error(OpenAIProvider::ERROR_OPENAI_AUTH, 'Invalid key');
        $this->assertFalse(OpenAIProvider::is_transient_error($error));
    }
}
