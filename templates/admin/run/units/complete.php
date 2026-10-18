<?= $prepend ?>
<p>Place Complete at the end of your questionnaire, after any quality checks.
    Participants must pass Quota before they can complete the study.</p>
<?php if ($active): ?><div class="alert alert-info" role="status"><strong>Quotas and completion are active.</strong>
    Reopen below to change quotas or provider settings. Change limits and counts under Quotas in the run menu.</div><?php endif; ?>
<?php if (!empty($reopened)): ?><div class="alert alert-warning" role="status">Configuration is open for editing.
    Current rules remain in use until you activate the changes. Counts for matching table and group codes are retained,
    even if their variables or ranges change. New codes start at zero. Review the counts after activation.</div><?php endif; ?>
<?php $usesProvider = ($provider['mode'] ?? 'none') === 'provider'; ?>
<div class="form-group">
    <label for="completion-body-<?= (int)$unitId ?>">Feedback text</label>
    <textarea class="form-control" id="completion-body-<?= (int)$unitId ?>" name="completion_body" data-editor="markdown" rows="6"><?= h($completionPage['body']) ?></textarea>
    <p class="help-block">You can use Markdown, as in Stop Point. Text and redirect timing remain editable after activation.</p>
</div>
<div class="form-group">
    <label for="completion-delay-<?= (int)$unitId ?>">Redirect delay (seconds)</label>
    <input class="form-control" id="completion-delay-<?= (int)$unitId ?>" type="number" name="completion_delay" min="0" max="2147483" step="1" value="<?= (int)$completionPage['redirect_delay'] ?>">
    <p class="help-block">With a provider, show this page before returning the participant. Allow enough time to read any debriefing. Use 0 for an immediate return.</p>
</div>
<div class="form-group">
    <label for="completion-message-<?= (int)$unitId ?>">Return message</label>
    <textarea class="form-control" id="completion-message-<?= (int)$unitId ?>" name="completion_message" rows="2"><?= h($completionPage['redirect_message']) ?></textarea>
    <p class="help-block">Shown only with a provider. Use <code>{seconds}</code> for the countdown and <code>{link}</code> for the individual return link. The link is always included.</p>
</div>
<div class="form-group">
    <label for="completion-link-text-<?= (int)$unitId ?>">Return link text</label>
    <input class="form-control" id="completion-link-text-<?= (int)$unitId ?>" name="completion_link_text" maxlength="255" value="<?= h($completionPage['redirect_link_text']) ?>">
</div>
<fieldset class="quota-provider" <?= $active ? 'disabled' : '' ?>>
    <div class="form-group">
        <label for="quota-provider-mode-<?= (int) ($unitId ?? 0) ?>">Panel provider</label>
        <select class="form-control" id="quota-provider-mode-<?= (int) ($unitId ?? 0) ?>" name="provider_mode">
            <option value="none" <?= !$usesProvider ? 'selected' : '' ?>>Without a panel provider</option>
            <option value="provider" <?= $usesProvider ? 'selected' : '' ?>>With a panel provider</option>
        </select>
    </div>
    <p class="help-block">Without a provider, participants finish on a formr page. No return links are needed.</p>
    <div class="quota-provider-details" <?= !$usesProvider ? 'hidden' : '' ?>>
    <p class="help-block">These settings apply to Complete, quota_full and screen_out throughout this run.</p>
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
<p class="help-block">Test participants see return links as text rather than returning to the provider.
    An ID from the provider is still needed to build these links.</p>
    </div>
</fieldset>
<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Complete">Save</a>
    <?php if (!$active): ?><button type="button" class="btn btn-primary quota-activate">Activate quotas and completion</button><?php endif; ?>
    <?php if ($active): ?><button type="button" class="btn btn-default quota-reopen">Reopen quota configuration</button><?php endif; ?>
</p>
<?php if (!$active): ?><p class="help-block">Save your quota tables first. This button saves the settings above and activates both Quota and Complete.
    Activation locks the configuration until you explicitly reopen it. Limits and counts remain editable under Quotas.</p><?php endif; ?>
