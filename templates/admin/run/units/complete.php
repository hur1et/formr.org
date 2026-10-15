<?= $prepend ?>
<div class="alert alert-warning">Development version: validate the entire study and provider return links before field use.</div>
<p>Place Complete after the main questionnaire and quality filters. Only participants who
    reach this unit after quota admission will count as completed.</p>
<?php if ($active): ?><div class="alert alert-success">Activated. These exit settings are fixed.</div><?php endif; ?>
<fieldset <?= $active ? 'disabled' : '' ?>>
    <p><label>Provider mode:
        <select class="form-control" name="provider_mode">
            <option value="">Choose explicitly</option>
            <option value="none" <?= ($provider['mode'] ?? '') === 'none' ? 'selected' : '' ?>>Without provider — show a local end page</option>
            <option value="provider" <?= ($provider['mode'] ?? '') === 'provider' ? 'selected' : '' ?>>Use provider return links</option>
        </select></label></p>
    <?php foreach (['entry_parameter'=>'Incoming provider ID parameter', 'placeholder'=>'Exact placeholder in return links',
        'complete'=>'Complete return URL', 'quota_full'=>'Quota full return URL', 'screen_out'=>'Screen out return URL'] as $key=>$label): ?>
        <p><label><?= h($label) ?>
            <input class="form-control" type="text" name="provider_<?= h($key) ?>" value="<?= h($provider[$key] ?? '') ?>" style="width:100%;min-width:250px">
        </label></p>
    <?php endforeach; ?>
</fieldset>
<p>Use the actual incoming parameter and placeholder supplied by your provider. Do not enter individual IDs.
    Test participants see return URLs as plain text; no provider request is made.</p>
<p><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Complete">Save</a></p>
