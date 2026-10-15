<?= $prepend ?>
<p class="text-warning"><small>Development version. Check the provider return links before field use.</small></p>
<p>This unit records completion after the main questionnaire and quality checks.
    A saved admission from Quota is required.</p>
<?php if ($active): ?><p class="text-success"><strong>Activated.</strong> The exit settings are fixed.</p><?php endif; ?>
<fieldset class="quota-provider" <?= $active ? 'disabled' : '' ?>>
    <div class="form-group">
        <label for="quota-provider-mode-<?= (int) ($unitId ?? 0) ?>">Panel provider</label>
        <select class="form-control" id="quota-provider-mode-<?= (int) ($unitId ?? 0) ?>" name="provider_mode">
            <option value="">Please choose</option>
            <option value="none" <?= ($provider['mode'] ?? '') === 'none' ? 'selected' : '' ?>>Without provider — local completion page</option>
            <option value="provider" <?= ($provider['mode'] ?? '') === 'provider' ? 'selected' : '' ?>>With provider — use return links</option>
        </select>
    </div>
    <?php foreach (['entry_parameter'=>'Incoming participant ID parameter', 'placeholder'=>'Placeholder in return links',
        'complete'=>'Completed (complete)', 'quota_full'=>'Quota full (quota_full)', 'screen_out'=>'Screened out (screen_out)'] as $key=>$label): ?>
        <div class="form-group">
            <label><?= h($label) ?>
                <input class="form-control" type="text" name="provider_<?= h($key) ?>" value="<?= h($provider[$key] ?? '') ?>">
            </label>
        </div>
    <?php endforeach; ?>
    <p class="help-block">For a panel provider, enter the actual incoming URL parameter, the supplied placeholder
        and all three return links. Individual participant IDs are captured on entry.</p>
</fieldset>
<p class="help-block">Test participants see return links as plain text. They are not redirected to the provider.</p>
<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Complete">Save</a></p>
