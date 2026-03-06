import $ from 'jquery';

export function initializeAiChatWidgets() {
    // Derive the run-relative AJAX URL from the current page path:
    // URL format: /{base}/run/{run_name}/{session_code}
    var parts  = window.location.pathname.replace(/^\//, '').split('/');
    var runIdx = parts.indexOf('run');
    if (runIdx === -1 || !parts[runIdx + 1]) return;
    var baseUrl = '/' + parts.slice(0, runIdx + 2).join('/');
    var aiUrl   = baseUrl + '/ajax_ai_complete';

    $('.item-ai-chat').each(function () {
        var $group   = $(this);
        var $widget  = $group.find('.ai-chat-widget');
        var $log     = $widget.find('.ai-chat-log');
        var $input   = $widget.find('.ai-chat-input');
        var $sendBtn = $widget.find('.ai-chat-send');
        var $hidden  = $widget.find('input[type="hidden"]');
        var $nextBtn = $('form.main_formr_survey .form-group.item-submit button');
        var minTurns = parseInt($widget.data('min-turns'), 10) || 0;
        var conversation = [];
        var turnCount    = 0;

        if (minTurns > 0) {
            $nextBtn.prop('disabled', true)
                    .attr('title', 'Bitte führe zuerst ' + minTurns + ' Nachrichten mit der KI.');
        }

        function appendMessage(role, content) {
            var cls   = role === 'user' ? 'ai-chat-msg-user' : 'ai-chat-msg-assistant';
            var label = role === 'user' ? 'Du' : 'KI';
            var $msg  = $('<div>').addClass('ai-chat-msg ' + cls);
            $msg.append($('<strong>').text(label + ': '));
            $msg.append($('<span>').text(content));
            $log.append($msg);
            $log[0].scrollTop = $log[0].scrollHeight;
        }

        function sendMessage() {
            var text = $input.val().trim();
            if (!text || $sendBtn.prop('disabled')) return;

            appendMessage('user', text);
            $input.val('').prop('disabled', true);
            $sendBtn.prop('disabled', true).text('\u2026');

            var payload = { prompt: text };
            if (conversation.length) payload.messages = conversation.slice();

            $.ajax({
                url:         aiUrl,
                type:        'POST',
                contentType: 'application/json',
                data:        JSON.stringify(payload),
                dataType:    'json',
            }).done(function (result) {
                var aiText = result.text || '';
                conversation.push({ role: 'user',      content: text   });
                conversation.push({ role: 'assistant', content: aiText });
                appendMessage('assistant', aiText);
                turnCount++;
                $hidden.val(JSON.stringify(conversation));

                // formr answered tracking
                $group.addClass('formr_answered');
                $group.find('input.item_answered').val(new Date().toISOString());

                if (minTurns > 0 && turnCount >= minTurns) {
                    $nextBtn.prop('disabled', false).removeAttr('title');
                }
            }).fail(function (xhr) {
                var err = (xhr.responseJSON && xhr.responseJSON.error)
                    ? xhr.responseJSON.error
                    : 'Verbindungsfehler \u2013 bitte erneut versuchen.';
                appendMessage('assistant', '[Fehler: ' + err + ']');
            }).always(function () {
                $input.prop('disabled', false).focus();
                $sendBtn.prop('disabled', false).text('Senden');
            });
        }

        // Enter = send | Shift+Enter = newline
        $input.on('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                e.stopPropagation();
                sendMessage();
            }
        });

        $sendBtn.on('click', function (e) {
            e.preventDefault();
            sendMessage();
        });
    });
}
