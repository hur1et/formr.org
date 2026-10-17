<?= $prepend ?>
<p class="text-warning"><small>Development version. Check the full study flow before field use.</small></p>
<?php if ($active): ?>
    <p class="text-success"><strong>Quotas activated.</strong> Categories and provider settings are fixed.
        Reload the editor to update the Complete panel.</p>
<?php endif; ?>
<p>Variables within a table are crossed. All tables must pass.
    Only participants who reach Complete are counted.</p>
<div class="quota-editor" data-max-groups="<?= QuotaRules::MAX_GROUPS ?>" data-readonly="<?= $active ? '1' : '0' ?>" data-sources="<?= h(json_encode($sources, JSON_THROW_ON_ERROR)) ?>">
    <input type="hidden" name="quota_tables" value="<?= h(json_encode((object) $tables, JSON_THROW_ON_ERROR)) ?>">
    <div class="quota-editor-error alert alert-danger" role="alert" hidden></div>
    <div class="quota-editor-tables"></div>
    <button type="button" class="btn btn-default btn-sm quota-add-table">Add quota table</button>
    <p class="help-block">Missing or unmatched answers are excluded through the quota-full exit.
        Admission does not reserve a place.</p>
</div>
<hr>
<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Quota"><?= $active ? 'Save description' : 'Save draft' ?></a>
    <?php if (!$active): ?><button type="button" class="btn btn-primary quota-activate">Save and activate</button><?php endif; ?>
</p>
<?php if (!$active): ?><p class="help-block">Save the Complete exit settings before activating.
    Activation fixes the categories and enables quota checks at this run position.</p><?php endif; ?>
