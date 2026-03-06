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
        var maxTurns = parseInt($widget.data('max-turns'), 10) || 0;
        var minWords = parseInt($widget.data('min-words'), 10) || 0;
        var maxWords = parseInt($widget.data('max-words'), 10) || 0;
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

        function appendHint(text) {
            var $hint = $('<div>').addClass('ai-chat-hint alert alert-warning').text(text);
            $log.append($hint);
            $log[0].scrollTop = $log[0].scrollHeight;
            setTimeout(function () { $hint.fadeOut(400, function () { $hint.remove(); }); }, 3500);
        }

        function countWords(str) {
            return str.replace(/\s+/g, ' ').trim().split(' ').filter(function (w) { return w.length > 0; }).length;
        }

        function lockChat(reason) {
            $input.prop('disabled', true);
            $sendBtn.prop('disabled', true);
            var $notice = $('<div>').addClass('ai-chat-hint alert alert-info').text(reason);
            $widget.find('.ai-chat-composer').after($notice);
        }

        function sendMessage() {
            var text = $input.val().trim();
            if (!text || $sendBtn.prop('disabled')) return;

            // Word-count validation
            var wc = countWords(text);
            if (minWords > 0 && wc < minWords) {
                appendHint('Bitte schreibe mindestens ' + minWords + ' Wörter (aktuell: ' + wc + ').');
                return;
            }
            if (maxWords > 0 && wc > maxWords) {
                appendHint('Bitte schreibe höchstens ' + maxWords + ' Wörter (aktuell: ' + wc + ').');
                return;
            }

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

                // Lock when max turns reached
                if (maxTurns > 0 && turnCount >= maxTurns) {
                    lockChat('Maximale Gesprächslänge von ' + maxTurns + ' Nachrichten erreicht.');
                    return; // skip re-enabling input/button below
                }

                $input.prop('disabled', false).focus();
                $sendBtn.prop('disabled', false).text('Senden');
            }).fail(function (xhr) {
                var err = (xhr.responseJSON && xhr.responseJSON.error)
                    ? xhr.responseJSON.error
                    : 'Verbindungsfehler \u2013 bitte erneut versuchen.';
                appendMessage('assistant', '[Fehler: ' + err + ']');
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
