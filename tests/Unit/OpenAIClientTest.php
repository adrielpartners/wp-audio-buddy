<?php
/**
 * Example unit test for OpenAIClient error normalization.
 *
 * Run with: phpunit tests/Unit/OpenAIClientTest.php
 */

use AdrielPartners\WpAudioBuddy\Integrations\OpenAIClient;

class OpenAIClientTest extends \PHPUnit\Framework\TestCase
{
    public function test_transient_error_detection_returns_true_for_rate_limit(): void
    {
        $error = new WP_Error(OpenAIClient::ERROR_OPENAI_RATE_LIMIT, 'Rate limited');
        $this->assertTrue(OpenAIClient::is_transient_error($error));
    }

    public function test_transient_error_detection_returns_false_for_auth_failure(): void
    {
        $error = new WP_Error(OpenAIClient::ERROR_OPENAI_AUTH, 'Invalid key');
        $this->assertFalse(OpenAIClient::is_transient_error($error));
    }
}