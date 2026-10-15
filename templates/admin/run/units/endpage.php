<?php echo $prepend; ?>

<p><label>Rückleitung:
    <select class="form-control" name="provider_exit">
        <option value="" <?= empty($providerExit) ? 'selected' : '' ?>>Normale Endseite</option>
        <option value="screen_out" <?= ($providerExit ?? '') === 'screen_out' ? 'selected' : '' ?>>Ausschluss über den Panelanbieter (screen_out)</option>
    </select></label></p>

<p>
    <label>Text der Endseite: <br />
        <textarea data-editor="markdown" style="width:100%;max-width:388px;" placeholder="Markdown ist möglich" name="body" rows="10" cols="60" class="form-control"><?= h($body) ?></textarea>
    </label>
</p>

<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Page">Speichern</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=Page">Testen</a>
</p>
