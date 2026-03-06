<?php

class AiChat_Item extends Item {

    public $type        = 'ai_chat';
    public $mysql_field = 'MEDIUMTEXT DEFAULT NULL';
    public $optional    = 1; // JS-seitig blockiert; PHP-Validation ignoriert

    private $minTurns = 3;

    protected function setMoreOptions() {
        if (!empty($this->type_options)) {
            $opts = trim($this->type_options);
            if (is_numeric($opts)) {
                $this->minTurns = max(0, (int) $opts);
            }
        }
    }

    protected function render_input() {
        $hiddenAttrs = array(
            'type'  => 'hidden',
            'name'  => $this->name,
            'id'    => 'item' . $this->id,
            'value' => h($this->value_validated ?: ''),
        );

        return sprintf(
            '<div class="ai-chat-widget" data-min-turns="%d">
                <div class="ai-chat-log"></div>
                <div class="ai-chat-composer">
                    <textarea class="ai-chat-input form-control" rows="3"
                        placeholder="Nachricht eingeben\xe2\x80\xa6 (Enter = Senden, Shift+Enter = neue Zeile)"></textarea>
                    <button type="button" class="ai-chat-send btn btn-primary">Senden</button>
                </div>
                <input %s />
            </div>',
            (int) $this->minTurns,
            self::_parseAttributes($hiddenAttrs)
        );
    }
}
