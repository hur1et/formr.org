<?php

/** Explicit provider mode and exact URL templates; no provider-specific guessing. */
class QuotaProviderConfig {
    private array $config;

    public function __construct(array $config) {
        if (!isset($config['mode']) || !in_array($config['mode'], ['none', 'provider'], true)) {
            throw new InvalidArgumentException('Bitte ausdrücklich auswählen, ob die Studie einen Panelanbieter verwendet.');
        }
        if ($config['mode'] === 'none') { $this->config = ['mode' => 'none']; return; }
        $parameter = $config['entry_parameter'] ?? null;
        $placeholder = $config['placeholder'] ?? null;
        if (!is_string($parameter) || !preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/', $parameter)
            || in_array($parameter, ['code','route','run_name','pageNo','formr_code','_pwa'], true)) {
            throw new InvalidArgumentException('Bitte den tatsächlichen URL-Parameter der Teilnehmer-ID angeben. Er darf keinen formr-Parameter überschreiben.');
        }
        if (!is_string($placeholder) || !preg_match('/\A[A-Za-z0-9_%{}.-]{1,100}\z/', $placeholder)) {
            throw new InvalidArgumentException('Bitte den exakten Platzhalter des Panelanbieters angeben.');
        }
        $this->config = ['mode'=>'provider','entry_parameter'=>$parameter,'placeholder'=>$placeholder];
        foreach (['complete', 'quota_full', 'screen_out'] as $outcome) {
            $url = $config[$outcome] ?? null;
            if (!is_string($url) || strlen($url) > 8000 || preg_match('/[\x00-\x20\x7f]/', $url)) {
                throw new InvalidArgumentException('Bitte gültige Rückleitungslinks für complete, quota_full und screen_out angeben.');
            }
            $probe = str_replace($placeholder, 'QuotaIdentityProbe', $url);
            $parts = parse_url($probe);
            if (!$parts || !in_array($parts['scheme'] ?? '', ['https','http'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || filter_var($probe, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Rückleitungslinks müssen vollständige HTTP- oder HTTPS-Adressen ohne eingebettete Zugangsdaten sein.');
            }
            $raw = parse_url($url);
            $count = substr_count($url, $placeholder);
            $valueCount = 0;
            foreach (explode('&', $raw['query'] ?? '') as $pair) {
                $value = explode('=', $pair, 2)[1] ?? '';
                $valueCount += substr_count($value, $placeholder);
            }
            if ($count === 0 || $count !== $valueCount) {
                throw new InvalidArgumentException('Der Platzhalter muss in einem Parameterwert nach dem Fragezeichen stehen, nicht im Servernamen, Pfad oder Fragment.');
            }
            $this->config[$outcome] = $url;
        }
    }

    public function toArray(): array { return $this->config; }

    public function entryParameter(): ?string { return $this->config['entry_parameter'] ?? null; }

    /** PHP already URL-decodes the incoming GET value. Encode it exactly once on output. */
    public function resolve(string $outcome, ?string $identity): ?string {
        if (!in_array($outcome, ['complete','quota_full','screen_out'], true)) {
            throw new InvalidArgumentException('Unbekanntes Rückleitungsziel.');
        }
        if ($this->config['mode'] === 'none') { return null; }
        if ($identity === null || $identity === '') {
            throw new DomainException('Die Teilnehmer-ID des Panelanbieters fehlt. Es wurde kein Rückleitungslink gesendet.');
        }
        return str_replace($this->config['placeholder'], rawurlencode($identity), $this->config[$outcome]);
    }
}
