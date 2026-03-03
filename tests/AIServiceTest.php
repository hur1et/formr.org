<?php
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AIService and its provider classes.
 *
 * These tests exercise pure logic (instantiation, configuration, validation)
 * without making real HTTP calls — API keys are intentionally fake.
 * Integration of prompt-length validation and rate-limiting lives in
 * ApiHelper and is covered by separate integration tests.
 */
class AIServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function claudeConfig(string $apiKey = 'sk-ant-test-key'): array
    {
        return [
            'provider'        => AIService::PROVIDER_CLAUDE,
            'claude_api_key'  => $apiKey,
            'claude_model'    => 'claude-sonnet-4-6',
            'openai_api_key'  => '',
            'openai_model'    => 'gpt-4o',
            'max_tokens'      => 256,
            'timeout_seconds' => 10,
        ];
    }

    private function openaiConfig(string $apiKey = 'sk-test-key'): array
    {
        return [
            'provider'        => AIService::PROVIDER_OPENAI,
            'claude_api_key'  => '',
            'claude_model'    => 'claude-sonnet-4-6',
            'openai_api_key'  => $apiKey,
            'openai_model'    => 'gpt-4o',
            'max_tokens'      => 256,
            'timeout_seconds' => 10,
        ];
    }

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public function testMaxPromptLengthConstant()
    {
        $this->assertEquals(32000, AIService::MAX_PROMPT_LENGTH);
    }

    public function testProviderConstants()
    {
        $this->assertEquals('claude', AIService::PROVIDER_CLAUDE);
        $this->assertEquals('openai', AIService::PROVIDER_OPENAI);
    }

    // -------------------------------------------------------------------------
    // getProviderName()
    // -------------------------------------------------------------------------

    public function testGetProviderNameClaude()
    {
        $ai = new AIService($this->claudeConfig());
        $this->assertEquals(AIService::PROVIDER_CLAUDE, $ai->getProviderName());
    }

    public function testGetProviderNameOpenAI()
    {
        $ai = new AIService($this->openaiConfig());
        $this->assertEquals(AIService::PROVIDER_OPENAI, $ai->getProviderName());
    }

    /** Unknown provider key is stored as-is in the config but AIService silently
     *  falls back to the Claude provider class (default branch in constructor). */
    public function testGetProviderNameUnknownReturnsConfigValue()
    {
        $config             = $this->claudeConfig();
        $config['provider'] = 'nonexistent';
        $ai = new AIService($config);
        $this->assertEquals('nonexistent', $ai->getProviderName());
    }

    // -------------------------------------------------------------------------
    // getModels()
    // -------------------------------------------------------------------------

    public function testGetModelsClaudeReturnsNonEmptyArray()
    {
        $ai     = new AIService($this->claudeConfig());
        $models = $ai->getModels();

        $this->assertTrue(is_array($models), 'getModels() must return an array');
        $this->assertNotEmpty($models);
        $this->assertContains('claude-sonnet-4-6', $models);
    }

    public function testGetModelsOpenAIReturnsNonEmptyArray()
    {
        $ai     = new AIService($this->openaiConfig());
        $models = $ai->getModels();

        $this->assertTrue(is_array($models), 'getModels() must return an array');
        $this->assertNotEmpty($models);
        $this->assertContains('gpt-4o', $models);
    }

    /** Models returned by the two providers must be disjoint. */
    public function testGetModelsProvidersReturnDistinctSets()
    {
        $claudeModels = (new AIService($this->claudeConfig()))->getModels();
        $openaiModels = (new AIService($this->openaiConfig()))->getModels();

        $overlap = array_intersect($claudeModels, $openaiModels);
        $this->assertEmpty($overlap, 'Claude and OpenAI model lists should not overlap');
    }

    // -------------------------------------------------------------------------
    // complete() — empty API key validation (no real HTTP call needed)
    // -------------------------------------------------------------------------

    public function testCompleteThrowsOnEmptyClaudeApiKey()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/claude_api_key/i');

        $ai = new AIService($this->claudeConfig(''));
        $ai->complete('Hello');
    }

    public function testCompleteThrowsOnEmptyOpenAIApiKey()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/openai_api_key/i');

        $ai = new AIService($this->openaiConfig(''));
        $ai->complete('Hello');
    }
}
