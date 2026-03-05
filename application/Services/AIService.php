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

    /** @var AIClaudeProvider|AIOpenAIProvider */
    private $provider;

    /** @var array */
    private $config;

    /**
     * Create an AIService instance from application config.
     *
     * @return AIService
     */
    public static function getInstance() {
        $config = Config::get('ai', array());
        return new self($config);
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
     * @param array  $options    Optional overrides: 'model', 'max_tokens'
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
 * Anthropic Claude provider for AIService.
 */
class AIClaudeProvider {

    const API_URL       = 'https://api.anthropic.com/v1/messages';
    const API_VERSION   = '2023-06-01';
    const DEFAULT_MODEL = 'claude-sonnet-4-6';

    private $apiKey;
    private $model;
    private $maxTokens;
    private $timeout;

    public function __construct(array $config) {
        $this->apiKey    = array_val($config, 'claude_api_key', '');
        $this->model     = array_val($config, 'claude_model', self::DEFAULT_MODEL);
        $this->maxTokens = (int) array_val($config, 'max_tokens', 1024);
        $this->timeout   = (int) array_val($config, 'timeout_seconds', 60);
    }

    public function complete($prompt, array $options = array()) {
        if (empty($this->apiKey)) {
            throw new Exception('Claude API key is not configured');
        }
        $model        = array_val($options, 'model',         $this->model);
        $maxTokens    = (int) array_val($options, 'max_tokens',    $this->maxTokens);
        $systemPrompt = array_val($options, 'system_prompt', '');
        $history      = array_val($options, 'messages',      null);
        $messages = array();
        if (!empty($history) && is_array($history)) {
            $messages = $history;
        }
        if (!empty($prompt)) {
            $messages[] = array('role' => 'user', 'content' => $prompt);
        }
        if (empty($messages)) {
            throw new Exception('No prompt or messages provided');
        }
        $requestBody = array(
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        );
        if (!empty($systemPrompt)) {
            $requestBody['system'] = $systemPrompt;
        }
        $body = json_encode($requestBody);
        $curlOptions = array(
            CURLOPT_TIMEOUT    => $this->timeout,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ),
        );
        $info = null;
        $raw  = CURL::HttpRequest(self::API_URL, $body, CURL::HTTP_METHOD_POST, $curlOptions, $info);
        $data = json_decode($raw, true);
        if ($data === null) {
            throw new Exception('Claude API returned invalid JSON (HTTP ' . $info['http_code'] . ')');
        }
        if ($info['http_code'] !== 200) {
            $errorMsg = !empty($data['error']['message']) ? $data['error']['message'] : 'Unknown Claude API error';
            throw new Exception('Claude API error (HTTP ' . $info['http_code'] . '): ' . $errorMsg);
        }
        $text = isset($data['content'][0]['text']) ? $data['content'][0]['text'] : '';
        if (empty($text)) {
            throw new Exception('Claude API returned an empty response text');
        }
        return array(
            'text'          => $text,
            'model'         => isset($data['model']) ? $data['model'] : $model,
            'provider'      => AIService::PROVIDER_CLAUDE,
            'input_tokens'  => isset($data['usage']['input_tokens'])  ? $data['usage']['input_tokens']  : null,
            'output_tokens' => isset($data['usage']['output_tokens']) ? $data['usage']['output_tokens'] : null,
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
class AIOpenAIProvider {

    const API_URL       = 'https://api.openai.com/v1/chat/completions';
    const DEFAULT_MODEL = 'gpt-4o';

    private $apiKey;
    private $model;
    private $maxTokens;
    private $timeout;

    public function __construct(array $config) {
        $this->apiKey    = array_val($config, 'openai_api_key', '');
        $this->model     = array_val($config, 'openai_model', self::DEFAULT_MODEL);
        $this->maxTokens = (int) array_val($config, 'max_tokens', 1024);
        $this->timeout   = (int) array_val($config, 'timeout_seconds', 60);
    }

    public function complete($prompt, array $options = array()) {
        if (empty($this->apiKey)) {
            throw new Exception('OpenAI API key is not configured');
        }
        $model        = array_val($options, 'model',         $this->model);
        $maxTokens    = (int) array_val($options, 'max_tokens',    $this->maxTokens);
        $systemPrompt = array_val($options, 'system_prompt', '');
        $history      = array_val($options, 'messages',      null);
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
        if (empty($messages)) {
            throw new Exception('No prompt or messages provided');
        }
        $body = json_encode(array(
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ));
        $curlOptions = array(
            CURLOPT_TIMEOUT    => $this->timeout,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ),
        );
        $info = null;
        $raw  = CURL::HttpRequest(self::API_URL, $body, CURL::HTTP_METHOD_POST, $curlOptions, $info);
        $data = json_decode($raw, true);
        if ($data === null) {
            throw new Exception('OpenAI API returned invalid JSON (HTTP ' . $info['http_code'] . ')');
        }
        if ($info['http_code'] !== 200) {
            $errorMsg = !empty($data['error']['message']) ? $data['error']['message'] : 'Unknown OpenAI API error';
            throw new Exception('OpenAI API error (HTTP ' . $info['http_code'] . '): ' . $errorMsg);
        }
        $text = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';
        if (empty($text)) {
            throw new Exception('OpenAI API returned an empty response text');
        }
        return array(
            'text'          => $text,
            'model'         => isset($data['model']) ? $data['model'] : $model,
            'provider'      => AIService::PROVIDER_OPENAI,
            'input_tokens'  => isset($data['usage']['prompt_tokens'])     ? $data['usage']['prompt_tokens']     : null,
            'output_tokens' => isset($data['usage']['completion_tokens']) ? $data['usage']['completion_tokens'] : null,
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
