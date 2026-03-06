import $ from 'jquery';

/**
 * Global handler for custom HTML chat widgets embedded in survey item labels.
 *
 * Protocol (Schlüssel-Schloss):
 *   HTML wraps the widget in:  <div class="fai-widget" data-fai-min="3"
 *                                   data-fai-system-prompt="...">
 *   Senden button uses:        onclick="faiSend(this)"
 *   Enter handling:            registered by initializeAiChatWidgets() below
 *   Inline <script> block:     can be removed entirely
 *
 * Falls back to the built-in .ai-chat-send button when no .fai-widget is found.
 */
window.faiSend = function (el) {
    var $widget = el ? $(el).closest('.fai-widget') : $('.fai-widget').first();

    if (!$widget.length) {
        // Fallback: official ai_chat item type
        $('.ai-chat-send').first().trigger('click');
        return false;
    }

    var inp     = $widget.find('#fai-input')[0];
    var btn     = $widget.find('#fai-btn')[0];
    var chat    = $widget.find('#fai-chat')[0];
    var countEl = $widget.find('#fai-count')[0];

    var t = inp ? inp.value.trim() : '';
    if (!t || (btn && btn.disabled)) return false;

    // Append user message
    if (chat) {
        var ud = document.createElement('div');
        ud.className = 'fai-msg fai-user';
        ud.textContent = t;
        chat.appendChild(ud);
    }
    if (inp) inp.value = '';
    if (btn) btn.disabled = true;

    // Typing indicator (unique ID so multiple widgets don't conflict)
    var tyId = 'fai-ty-' + Date.now();
    if (chat) {
        var ty = document.createElement('div');
        ty.className = 'fai-msg fai-bot';
        ty.id = tyId;
        ty.innerHTML = '<em>tippt\u2026</em>';
        chat.appendChild(ty);
        chat.scrollTop = chat.scrollHeight;
    }

    // Derive AI endpoint from the run's data-url on <body> — works for both
    // real runs and admin test-runs (form action is replaced but data-url is not).
    var runUrl = (($('body').data('url')) || '').replace(/\/+$/, '');
    var aiUrl  = runUrl ? runUrl + '/ajax_ai_complete' : '';
    if (!aiUrl) {
        var te = document.getElementById(tyId); if (te) te.remove();
        if (btn) btn.disabled = false;
        return false;
    }

    // System prompt from data attribute (replaces the hardcoded value in <script>)
    var systemPrompt = $widget.data('fai-system-prompt') || '';
    var payload = { prompt: t };
    if (systemPrompt) payload.system_prompt = systemPrompt;

    fetch(aiUrl, {
        method:      'POST',
        headers:     { 'Content-Type': 'application/json' },
        body:        JSON.stringify(payload),
        credentials: 'same-origin'
    })
    .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function (d) {
        var te = document.getElementById(tyId); if (te) te.remove();
        var txt = d.text || (d.error ? 'Fehler: ' + d.error : 'Fehler');
        if (chat) {
            var bd = document.createElement('div');
            bd.className = 'fai-msg fai-bot';
            bd.textContent = txt;
            chat.appendChild(bd);
            chat.scrollTop = chat.scrollHeight;
        }
        if (countEl) {
            var c   = parseInt(countEl.textContent || '0') + 1;
            countEl.textContent = c;
            var min = parseInt($widget.data('fai-min') || '0');
            if (min > 0 && c >= min) {
                $('form.main_formr_survey button[type="submit"]')
                    .not('#fai-btn').prop('disabled', false);
            }
        }
        if (btn) btn.disabled = false;
        if (inp) inp.focus();
    })
    .catch(function () {
        var te = document.getElementById(tyId); if (te) te.remove();
        if (chat) {
            var ed = document.createElement('div');
            ed.className = 'fai-msg fai-bot';
            ed.textContent = 'Verbindungsfehler.';
            chat.appendChild(ed);
        }
        if (btn) btn.disabled = false;
    });

    return false; // prevents form submission when called from onclick=
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
    // Use the run's data-url from <body> — more reliable than form action,
    // because the admin test-run replaces the form action with the admin URL
    // but leaves data-url pointing to the real run endpoint.
    var runUrl = (($('body').data('url')) || '').replace(/\/+$/, '');
    var aiUrl  = runUrl ? runUrl + '/ajax_ai_complete'
                        : formAction + '/ajax_ai_complete';

    // The submit button for the survey form
    var $nextBtn = $form.find('button[type="submit"]');

    var ajaxInFlight = false;

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
                e.preventDefault();
                e.stopImmediatePropagation();
                sendMessage();
            }
        });

        $sendBtn.on('click', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            sendMessage();
        });
    });

    // ── Custom .fai-widget elements (HTML widgets in Excel item labels) ─────────
    // Initialises Enter handling and initial Weiter-button lock.
    // The actual send logic lives in window.faiSend above.
    $('.fai-widget').each(function () {
        var $widget = $(this);
        var $inp    = $widget.find('#fai-input');
        var min     = parseInt($widget.data('fai-min') || '0');

        // Enter in the text input → faiSend, not form submit
        if ($inp.length) {
            $inp.on('keydown.faiwidget', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    window.faiSend($widget.find('#fai-btn')[0]);
                }
            });
        }

        // Lock the Weiter button until the minimum number of messages is reached
        if (min > 0) {
            $('form.main_formr_survey button[type="submit"]')
                .not('#fai-btn').prop('disabled', true);
        }
    });
}
