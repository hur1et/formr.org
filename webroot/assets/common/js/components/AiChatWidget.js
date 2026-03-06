import $ from 'jquery';

/**
 * Global helper for custom survey buttons: onclick="faiSend('Your prompt text')"
 * Pre-fills the chat input (optional) and triggers the send.
 * Returns false so onclick= does not submit the surrounding form.
 */
window.faiSend = function (text) {
    if (typeof text === 'string' && text) {
        $('.ai-chat-input').first().val(text);
    }
    $('.ai-chat-send').first().trigger('click');
    return false;
};

export function initializeAiChatWidgets() {
    // Derive the AI endpoint from the survey form's action attribute.
    // The form action is already set to the run URL, e.g. /run/{run_name}/
    var $form = $('form.main_formr_survey');
    console.log('[AiChat] init, form found:', $form.length, 'items:', $('.item-ai_chat').length);
    if (!$form.length) return;

    var formAction = ($form.attr('action') || '').replace(/\/+$/, '');
    if (!formAction) {
        // Fallback: parse from window.location
        var parts  = window.location.pathname.replace(/^\//, '').split('/');
        var runIdx = parts.indexOf('run');
        if (runIdx === -1 || !parts[runIdx + 1]) return;
        formAction = '/' + parts.slice(0, runIdx + 2).join('/');
    }
    var aiUrl = formAction + '/ajax_ai_complete';

    // The submit button for the survey form
    var $nextBtn = $form.find('button[type="submit"]');

    var ajaxInFlight = false;

    // Debug: log all form submit events with a stack trace to find the trigger
    $form.on('submit.debug', function (e) {
        console.log('[AiChat] form submit fired! ajaxInFlight=' + ajaxInFlight);
        console.trace();
    });

    // Block form submission entirely while an AJAX call is in flight
    $form.on('submit.aichat', function (e) {
        if (ajaxInFlight) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }
    });

    // Prevent Enter from submitting the form when focused inside any chat input.
    // This delegate handler fires even before widget-level handlers are initialised.
    $form.on('keydown.aichat', '.ai-chat-input', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            e.stopPropagation();
        }
    });

    // item-ai_chat: underscore because PHP $type = 'ai_chat' → class = 'item-ai_chat'
    $('.item-ai_chat').each(function () {
        var $group   = $(this);
        var $widget  = $group.find('.ai-chat-widget');
        var $log     = $widget.find('.ai-chat-log');
        var $input   = $widget.find('.ai-chat-input');
        var $sendBtn = $widget.find('.ai-chat-send');
        var $hidden  = $widget.find('input[type="hidden"]');
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
            if (!text || $sendBtn.prop('disabled') || ajaxInFlight) return;

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
            $nextBtn.prop('disabled', true); // keep next-btn locked during AJAX
            ajaxInFlight = true;

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
                // Restore next-btn state on failure
                if (minTurns === 0 || turnCount >= minTurns) {
                    $nextBtn.prop('disabled', false).removeAttr('title');
                }
            }).always(function () {
                ajaxInFlight = false;
            });
        }

        // Enter = send | Shift+Enter = newline
        $input.on('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                console.log('[AiChat] Enter key captured in textarea, calling sendMessage');
                e.preventDefault();
                e.stopImmediatePropagation();
                sendMessage();
            }
        });

        $sendBtn.on('click', function (e) {
            console.log('[AiChat] Senden button clicked, calling sendMessage');
            e.preventDefault();
            e.stopImmediatePropagation();
            sendMessage();
        });
    });
}
