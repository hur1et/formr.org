<?php

/**
 * AI Service - abstraction layer for LLM API calls.
 * Supports Claude (Anthropic) and OpenAI as providers.
 *
 * Configuration (config/settings.php):
 *   $settings['ai'] = array(
 *       'provider'         => 'claude',               // 'claude' or 'openai'
 *       'claude_api_key'   => 'sk-ant-...',
 *       'claude_model'     => 'claude-sonnet-4-6',
 *       'openai_api_key'   => 'sk-...',
 *       'openai_model'     => 'gpt-4o',
 *       'max_tokens'       => 1024,
 *       'timeout_seconds'  => 60,                     // AI-API request timeout
 *   );
 *
 * Usage:
 *   $ai = AIService::getInstance();
 *   $result = $ai->complete('What is 2+2?');
 *   echo $result['text'];
 */
class AIService {

    const PROVIDER_CLAUDE = 'claude';
    const PROVIDER_OPENAI = 'openai';

    /** Absolute upper bound for prompt length (~8k tokens safety cap). */
    const MAX_PROMPT_LENGTH = 32000;

    /** @var AbstractAIProvider */
    private $provider;

    /** @var array */
    private $config;

    /**
     * Return the merged AI config: PHP config file as base, DB settings (ai_config) on top.
     * Admins can override config via /admin/account#ai; the DB values take precedence.
     *
     * @return array
     */
    public static function getConfig() {
        $config  = Config::get('ai', array());
        $dbJson  = Site::getSettings('ai_config', null);
        if ($dbJson) {
            $dbConfig = json_decode($dbJson, true);
            if (is_array($dbConfig)) {
                $config = array_merge($config, $dbConfig);
            }
        }
        return $config;
    }

    /**
     * Returns true if the AI feature is enabled in config/DB settings.
     *
     * @return bool
     */
    public static function isEnabled() {
        $config  = self::getConfig();
        $enabled = array_val($config, 'enabled', true);
        return ($enabled === true || $enabled === 1 || $enabled === '1');
    }

    public static function getInstance() {
        return new self(self::getConfig());
    }

    /**
     * @param array $config  AI config array (see class docblock)
     */
    public function __construct(array $config) {
        $this->config = $config;
        $providerName = array_val($config, 'provider', self::PROVIDER_CLAUDE);

        if ($providerName === self::PROVIDER_OPENAI) {
            $this->provider = new AIOpenAIProvider($config);
        } else {
            $this->provider = new AIClaudeProvider($config);
        }
    }

    /**
     * Send a prompt to the configured AI provider and return the completion.
     *
     * @param string $prompt     The user message / prompt text
     * @param array  $options    Optional overrides: 'model', 'max_tokens', 'system_prompt', 'messages'
     * @return array {
     *   string  text          The generated text
     *   string  model         Model identifier used
     *   string  provider      Provider name ('claude' or 'openai')
     *   int|null input_tokens  Tokens consumed by the prompt
     *   int|null output_tokens Tokens generated in the response
     * }
     * @throws Exception on API errors or missing configuration
     */
    public function complete($prompt, array $options = array()) {
        return $this->provider->complete($prompt, $options);
    }

    /**
     * Return a list of known model identifiers for the active provider.
     *
     * @return string[]
     */
    public function getModels() {
        return $this->provider->getModels();
    }

    /**
     * Return the name of the currently active provider.
     *
     * @return string
     */
    public function getProviderName() {
        return array_val($this->config, 'provider', self::PROVIDER_CLAUDE);
    }
}


/**
 * Abstract base class for AI provider implementations.
 * Handles shared CURL execution, response validation, and the complete() flow.
 * Subclasses implement provider-specific message building, headers, and text extraction.
 */
abstract class AbstractAIProvider {

    protected $apiKey;
    protected $model;
    protected $maxTokens;
    protected $timeout;

    protected function initCommonConfig(array $config, $apiKeyField, $modelField, $defaultModel) {
        $this->apiKey    = array_val($config, $apiKeyField,        '');
        $this->model     = array_val($config, $modelField,         $defaultModel);
        $this->maxTokens = (int) array_val($config, 'max_tokens',      1024);
        $this->timeout   = (int) array_val($config, 'timeout_seconds',   60);
    }

    /**
     * Execute a POST request to the provider API and return the decoded JSON response.
     *
     * @throws Exception on network error, invalid JSON, or non-200 HTTP status
     */
    protected function executeRequest($url, array $headers, $body) {
        $curlOptions = array(
            CURLOPT_TIMEOUT    => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
        );
        $info = null;
        $raw  = CURL::HttpRequest($url, $body, CURL::HTTP_METHOD_POST, $curlOptions, $info);
        $data = json_decode($raw, true);
        if ($data === null) {
            throw new Exception($this->providerName() . ' API returned invalid JSON (HTTP ' . $info['http_code'] . ')');
        }
        if ($info['http_code'] !== 200) {
            $errorMsg = !empty($data['error']['message']) ? $data['error']['message'] : 'Unknown ' . $this->providerName() . ' API error';
            throw new Exception($this->providerName() . ' API error (HTTP ' . $info['http_code'] . '): ' . $errorMsg);
        }
        return $data;
    }

    public function complete($prompt, array $options = array()) {
        if (empty($this->apiKey)) {
            throw new Exception($this->providerName() . ' API key is not configured');
        }
        $model        = array_val($options, 'model',         $this->model);
        $maxTokens    = (int) array_val($options, 'max_tokens',    $this->maxTokens);
        $systemPrompt = array_val($options, 'system_prompt', '');
        $history      = array_val($options, 'messages',      null);

        $messages = $this->buildMessages($prompt, $systemPrompt, $history);
        if (empty($messages)) {
            throw new Exception('No prompt or messages provided');
        }
        $body = json_encode($this->buildRequestBody($model, $maxTokens, $messages, $systemPrompt));
        $data = $this->executeRequest($this->getApiUrl(), $this->buildHeaders(), $body);
        $text = $this->extractText($data);
        if (empty($text)) {
            throw new Exception($this->providerName() . ' API returned an empty response text');
        }
        $tokens = $this->extractTokens($data);
        return array(
            'text'          => $text,
            'model'         => isset($data['model']) ? $data['model'] : $model,
            'provider'      => $this->providerConstant(),
            'input_tokens'  => $tokens['input'],
            'output_tokens' => $tokens['output'],
        );
    }

    /** Human-readable provider name for error messages (e.g. 'Claude', 'OpenAI'). */
    abstract protected function providerName();

    /** Provider constant for the 'provider' field in results (e.g. AIService::PROVIDER_CLAUDE). */
    abstract protected function providerConstant();

    abstract protected function getApiUrl();
    abstract protected function buildHeaders();

    /**
     * Build the messages array from prompt, system prompt, and history.
     * Note: Claude handles system prompts separately (in buildRequestBody),
     * while OpenAI embeds them as the first message here.
     */
    abstract protected function buildMessages($prompt, $systemPrompt, $history);

    /**
     * Build the full request body array from model, tokens, messages, and system prompt.
     * $systemPrompt is passed again so Claude can add it as a top-level 'system' key.
     */
    abstract protected function buildRequestBody($model, $maxTokens, array $messages, $systemPrompt);

    /** Extract the response text from the decoded API response. */
    abstract protected function extractText(array $data);

    /** Return array('input' => int|null, 'output' => int|null) from decoded API response. */
    abstract protected function extractTokens(array $data);

    abstract public function getModels();
}


/**
 * Anthropic Claude provider for AIService.
 */
class AIClaudeProvider extends AbstractAIProvider {

    const API_URL       = 'https://api.anthropic.com/v1/messages';
    const API_VERSION   = '2023-06-01';
    const DEFAULT_MODEL = 'claude-sonnet-4-6';

    public function __construct(array $config) {
        $this->initCommonConfig($config, 'claude_api_key', 'claude_model', self::DEFAULT_MODEL);
    }

    protected function providerName()     { return 'Claude'; }
    protected function providerConstant() { return AIService::PROVIDER_CLAUDE; }
    protected function getApiUrl()        { return self::API_URL; }

    protected function buildHeaders() {
        return array(
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
        );
    }

    protected function buildMessages($prompt, $systemPrompt, $history) {
        // Claude: system prompt is a top-level field, not a message
        $messages = array();
        if (!empty($history) && is_array($history)) {
            $messages = $history;
        }
        if (!empty($prompt)) {
            $messages[] = array('role' => 'user', 'content' => $prompt);
        }
        return $messages;
    }

    protected function buildRequestBody($model, $maxTokens, array $messages, $systemPrompt) {
        $body = array(
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        );
        if (!empty($systemPrompt)) {
            $body['system'] = $systemPrompt;
        }
        return $body;
    }

    protected function extractText(array $data) {
        return isset($data['content'][0]['text']) ? $data['content'][0]['text'] : '';
    }

    protected function extractTokens(array $data) {
        return array(
            'input'  => isset($data['usage']['input_tokens'])  ? $data['usage']['input_tokens']  : null,
            'output' => isset($data['usage']['output_tokens']) ? $data['usage']['output_tokens'] : null,
        );
    }

    public function getModels() {
        return array(
            'claude-opus-4-6',
            'claude-sonnet-4-6',
            'claude-haiku-4-5-20251001',
        );
    }
}


/**
 * OpenAI provider for AIService.
 */
class AIOpenAIProvider extends AbstractAIProvider {

    const API_URL       = 'https://api.openai.com/v1/chat/completions';
    const DEFAULT_MODEL = 'gpt-4o';

    public function __construct(array $config) {
        $this->initCommonConfig($config, 'openai_api_key', 'openai_model', self::DEFAULT_MODEL);
    }

    protected function providerName()     { return 'OpenAI'; }
    protected function providerConstant() { return AIService::PROVIDER_OPENAI; }
    protected function getApiUrl()        { return self::API_URL; }

    protected function buildHeaders() {
        return array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        );
    }

    protected function buildMessages($prompt, $systemPrompt, $history) {
        // OpenAI: system prompt is embedded as the first message with role 'system'
        $messages = array();
        if (!empty($systemPrompt)) {
            $messages[] = array('role' => 'system', 'content' => $systemPrompt);
        }
        if (!empty($history) && is_array($history)) {
            foreach ($history as $msg) $messages[] = $msg;
        }
        if (!empty($prompt)) {
            $messages[] = array('role' => 'user', 'content' => $prompt);
        }
        return $messages;
    }

    protected function buildRequestBody($model, $maxTokens, array $messages, $systemPrompt) {
        // $systemPrompt already embedded in $messages by buildMessages()
        return array(
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        );
    }

    protected function extractText(array $data) {
        return isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';
    }

    protected function extractTokens(array $data) {
        return array(
            'input'  => isset($data['usage']['prompt_tokens'])     ? $data['usage']['prompt_tokens']     : null,
            'output' => isset($data['usage']['completion_tokens']) ? $data['usage']['completion_tokens'] : null,
        );
    }

    public function getModels() {
        return array(
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo',
            'gpt-3.5-turbo',
        );
    }
}


/**
 * Utility class for writing AI call records to survey_ai_log.
 * Used by both RunController (participant calls, user_id=0) and ApiHelper (researcher API calls).
 */
class AILogger {

    /**
     * Write an AI call entry to survey_ai_log. Non-fatal: DB errors are logged but don't block.
     *
     * @param object $db           DB instance (must have insert())
     * @param array  $result       Return value from AIService->complete()
     * @param string $prompt       The original prompt text
     * @param array  $messages     Conversation history before the current prompt
     * @param int    $userId       0 for run participants, >0 for authenticated researchers
     * @param string $sessionToken Run session token (empty string for API calls)
     */
    public static function log($db, array $result, $prompt, array $messages, $userId, $sessionToken = '') {
        $conversation = array();
        if (!empty($messages)) $conversation = $messages;
        if (!empty($prompt))   $conversation[] = array('role' => 'user',      'content' => $prompt);
        $conversation[]         = array('role' => 'assistant', 'content' => $result['text']);

        $convJson = null;
        if (!empty($conversation)) {
            $encoded = json_encode($conversation, JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $convJson = strlen($encoded) <= 1048576
                    ? $encoded
                    : json_encode(array_slice($conversation, -20), JSON_UNESCAPED_UNICODE);
            }
        }

        try {
            $db->insert('survey_ai_log', array(
                'user_id'           => (int) $userId,
                'session_token'     => (string) $sessionToken,
                'provider'          => array_val($result, 'provider', ''),
                'model'             => array_val($result, 'model',    ''),
                'input_tokens'      => (int) array_val($result, 'input_tokens',  0),
                'output_tokens'     => (int) array_val($result, 'output_tokens', 0),
                'prompt_text'       => mb_substr((string) $prompt,         0, 60000, 'UTF-8'),
                'response_text'     => mb_substr((string) $result['text'], 0, 60000, 'UTF-8'),
                'conversation_json' => $convJson,
                'created'           => date('Y-m-d H:i:s'),
            ));
        } catch (Throwable $e) {
            formr_log_exception($e, 'AI_LOG');
        }
    }
}
