<?php

/** Explicit provider mode and exact URL templates; no provider-specific guessing. */
class QuotaProviderConfig {
    private array $config;

    public function __construct(array $config) {
        if (!isset($config['mode']) || !in_array($config['mode'], ['none', 'provider'], true)) {
            throw new InvalidArgumentException('Choose whether your study uses a panel provider.');
        }
        if ($config['mode'] === 'none') { $this->config = ['mode' => 'none']; return; }
        $parameter = $config['entry_parameter'] ?? null;
        $placeholder = $config['placeholder'] ?? null;
        if (!is_string($parameter) || !preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/', $parameter)
            || in_array($parameter, ['code','route','run_name','pageNo','formr_code','_pwa'], true)) {
            throw new InvalidArgumentException('Enter the participant ID parameter from your entry link. Use letters, numbers, underscores or hyphens, starting with a letter, and avoid names already used by formr.');
        }
        if (!is_string($placeholder) || !preg_match('/\A[A-Za-z0-9_%{}.-]{1,100}\z/', $placeholder)) {
            throw new InvalidArgumentException('Copy the exact ID placeholder supplied by your provider, for example %PANEL_ID%.');
        }
        $this->config = ['mode'=>'provider','entry_parameter'=>$parameter,'placeholder'=>$placeholder];
        foreach (['complete'=>'Complete return link', 'quota_full'=>'Quota full return link', 'screen_out'=>'Screen-out return link'] as $outcome=>$label) {
            $url = $config[$outcome] ?? null;
            if (!is_string($url) || strlen($url) > 8000 || preg_match('/[\x00-\x20\x7f]/', $url)) {
                throw new InvalidArgumentException('Enter a valid URL in ' . $label . '.');
            }
            $probe = str_replace($placeholder, 'QuotaIdentityProbe', $url);
            $parts = parse_url($probe);
            if (!$parts || !in_array($parts['scheme'] ?? '', ['https','http'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || filter_var($probe, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException($label . ' must start with http:// or https:// and must not include a username or password.');
            }
            $raw = parse_url($url);
            $count = substr_count($url, $placeholder);
            $valueCount = 0;
            foreach (explode('&', $raw['query'] ?? '') as $pair) {
                $value = explode('=', $pair, 2)[1] ?? '';
                $valueCount += substr_count($value, $placeholder);
            }
            if ($count === 0 || $count !== $valueCount) {
                throw new InvalidArgumentException($label . ' must contain the ID placeholder in a URL parameter value (after an equals sign).');
            }
            $this->config[$outcome] = $url;
        }
    }

    public function toArray(): array { return $this->config; }

    public function entryParameter(): ?string { return $this->config['entry_parameter'] ?? null; }

    /** PHP already URL-decodes the incoming GET value. Encode it exactly once on output. */
    public function resolve(string $outcome, ?string $identity): ?string {
        if (!in_array($outcome, ['complete','quota_full','screen_out'], true)) {
            throw new InvalidArgumentException('Unknown provider exit.');
        }
        if ($this->config['mode'] === 'none') { return null; }
        if ($identity === null || $identity === '') {
            throw new DomainException('The provider identity is missing. No return link was sent.');
        }
        return str_replace($this->config['placeholder'], rawurlencode($identity), $this->config[$outcome]);
    }
}
