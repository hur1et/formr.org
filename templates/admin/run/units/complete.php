<?= $prepend ?>
<p>Place Complete at the end of your questionnaire, after any quality checks.
    Participants must pass Quota before they can complete the study.</p>
<?php if ($active): ?><p>The quotas are active, so these provider settings can no longer be changed.</p><?php endif; ?>
<fieldset class="quota-provider" <?= $active ? 'disabled' : '' ?>>
    <div class="form-group">
        <label for="quota-provider-mode-<?= (int) ($unitId ?? 0) ?>">Panel provider</label>
        <select class="form-control" id="quota-provider-mode-<?= (int) ($unitId ?? 0) ?>" name="provider_mode">
            <option value="">Please choose</option>
            <option value="none" <?= ($provider['mode'] ?? '') === 'none' ? 'selected' : '' ?>>Without a panel provider</option>
            <option value="provider" <?= ($provider['mode'] ?? '') === 'provider' ? 'selected' : '' ?>>With a panel provider</option>
        </select>
    </div>
    <p class="help-block">Without a provider, participants finish on a formr page.
        Fill in the fields below only if you use a panel provider.</p>
    <?php foreach (['entry_parameter'=>'Participant ID parameter', 'placeholder'=>'ID placeholder in return links',
        'complete'=>'Complete return link', 'quota_full'=>'Quota full return link', 'screen_out'=>'Screen-out return link'] as $key=>$label): ?>
        <div class="form-group">
            <label><?= h($label) ?>
                <input class="form-control" type="text" name="provider_<?= h($key) ?>" value="<?= h($provider[$key] ?? '') ?>">
            </label>
            <?php if ($key==='entry_parameter'): ?>
                <p class="help-block">For an entry link ending in <code>?panel_id=abc123</code>, enter <code>panel_id</code>.</p>
            <?php elseif ($key==='placeholder'): ?>
                <p class="help-block">Copy the placeholder supplied by your provider, e.g. <code>%PANEL_ID%</code>.
                    Do not enter an individual participant's ID.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <p class="help-block">Paste all three return links from your provider, including the ID placeholder.
        formr replaces it with the ID received when that participant entered the study.</p>
</fieldset>
<p class="help-block">Test participants see return links as text rather than returning to the provider.
    An ID from the provider is still needed to build these links.</p>
<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Complete">Save</a></p>
