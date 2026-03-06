<?php

class AiChat_Item extends Item {

    public $type        = 'ai_chat';
    public $mysql_field = 'MEDIUMTEXT DEFAULT NULL';
    public $optional    = 1; // JS-seitig blockiert; PHP-Validation ignoriert

    private $minTurns = null; // null = use global default
    private $maxTurns = null;
    private $minWords = null;
    private $maxWords = null;

    protected function setMoreOptions() {
        if (!empty($this->type_options)) {
            // Supports comma-separated values: minTurns[,maxTurns[,minWords[,maxWords]]]
            // A single integer is treated as minTurns for backward compatibility.
            $parts = array_map('trim', explode(',', $this->type_options));
            if (isset($parts[0]) && is_numeric($parts[0])) {
                $this->minTurns = max(0, (int) $parts[0]);
            }
            if (isset($parts[1]) && is_numeric($parts[1])) {
                $this->maxTurns = max(0, (int) $parts[1]);
            }
            if (isset($parts[2]) && is_numeric($parts[2])) {
                $this->minWords = max(0, (int) $parts[2]);
            }
            if (isset($parts[3]) && is_numeric($parts[3])) {
                $this->maxWords = max(0, (int) $parts[3]);
            }
        }
    }

    private function resolveConfig() {
        $cfg = AIService::getConfig();
        return array(
            'minTurns' => $this->minTurns !== null ? $this->minTurns : (int) array_val($cfg, 'min_turns', 3),
            'maxTurns' => $this->maxTurns !== null ? $this->maxTurns : (int) array_val($cfg, 'max_turns', 0),
            'minWords' => $this->minWords !== null ? $this->minWords : (int) array_val($cfg, 'min_words', 0),
            'maxWords' => $this->maxWords !== null ? $this->maxWords : (int) array_val($cfg, 'max_words', 0),
        );
    }

    protected function render_input() {
        $c = $this->resolveConfig();

        $hiddenAttrs = array(
            'type'  => 'hidden',
            'name'  => $this->name,
            'id'    => 'item' . $this->id,
            'value' => h($this->value_validated ?: ''),
        );

        return sprintf(
            '<div class="ai-chat-widget" data-min-turns="%d" data-max-turns="%d" data-min-words="%d" data-max-words="%d">
                <div class="ai-chat-log"></div>
                <div class="ai-chat-composer">
                    <textarea class="ai-chat-input form-control" rows="3"
                        placeholder="Nachricht eingeben\xe2\x80\xa6 (Enter = Senden, Shift+Enter = neue Zeile)"></textarea>
                    <button type="button" class="ai-chat-send btn btn-primary">Senden</button>
                </div>
                <input %s />
            </div>',
            $c['minTurns'],
            $c['maxTurns'],
            $c['minWords'],
            $c['maxWords'],
            self::_parseAttributes($hiddenAttrs)
        );
    }
}
