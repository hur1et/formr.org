<?= $prepend ?>
<p class="text-warning"><small>Entwicklungsversion. Vor dem Feldeinsatz auch die Rückleitungen zum Panelanbieter prüfen.</small></p>
<p>Dieser Baustein zählt den Abschluss nach dem Hauptfragebogen und den Qualitätsprüfungen.
    Voraussetzung ist eine gespeicherte Zulassung durch das Quotenmodul.</p>
<?php if ($active): ?><p class="text-success"><strong>Aktiviert.</strong> Die Rückleitung ist festgelegt.</p><?php endif; ?>
<fieldset class="quota-provider" <?= $active ? 'disabled' : '' ?>>
    <div class="form-group">
        <label for="quota-provider-mode-<?= (int) ($unitId ?? 0) ?>">Panelanbieter</label>
        <select class="form-control" id="quota-provider-mode-<?= (int) ($unitId ?? 0) ?>" name="provider_mode">
            <option value="">Bitte auswählen</option>
            <option value="none" <?= ($provider['mode'] ?? '') === 'none' ? 'selected' : '' ?>>Ohne Panelanbieter – lokale Abschlussseite</option>
            <option value="provider" <?= ($provider['mode'] ?? '') === 'provider' ? 'selected' : '' ?>>Mit Panelanbieter – Rückleitungslinks verwenden</option>
        </select>
    </div>
    <?php foreach (['entry_parameter'=>'URL-Parameter der eingehenden Teilnehmer-ID', 'placeholder'=>'Platzhalter in den Rückleitungslinks',
        'complete'=>'Abgeschlossen (complete)', 'quota_full'=>'Quote voll (quota_full)', 'screen_out'=>'Ausgeschlossen (screen_out)'] as $key=>$label): ?>
        <div class="form-group">
            <label><?= h($label) ?>
                <input class="form-control" type="text" name="provider_<?= h($key) ?>" value="<?= h($provider[$key] ?? '') ?>">
            </label>
        </div>
    <?php endforeach; ?>
    <p class="help-block">Bei einem Panelanbieter den tatsächlichen URL-Parameter, den vorgegebenen Platzhalter
        und alle drei Rückleitungslinks eintragen. Individuelle Teilnehmer-IDs werden beim Eintritt übernommen.</p>
</fieldset>
<p class="help-block">Bei Testteilnahmen werden Rückleitungslinks nur als Text angezeigt. Es erfolgt keine Weiterleitung zum Anbieter.</p>
<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Complete">Speichern</a></p>
