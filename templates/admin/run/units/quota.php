<?= $prepend ?>
<?php if ($active): ?>
    <p><strong>Quotas are active.</strong> You can change limits and counts under Quotas in the run menu.
        Categories and provider settings can no longer be changed.</p>
<?php endif; ?>
<p>Set limits for groups of participants. Only those who reach Complete are counted.</p>
<p>Combine variables in one table to cross them, or add tables for separate targets.</p>
<div class="quota-editor" data-max-groups="<?= QuotaRules::MAX_GROUPS ?>" data-readonly="<?= $active ? '1' : '0' ?>" data-sources="<?= h(json_encode($sources, JSON_THROW_ON_ERROR)) ?>">
    <input type="hidden" name="quota_tables" value="<?= h(json_encode((object) $tables, JSON_THROW_ON_ERROR)) ?>">
    <div class="quota-editor-error alert alert-danger" role="alert" hidden></div>
    <div class="quota-editor-tables"></div>
    <button type="button" class="btn btn-default btn-sm quota-add-table">Add quota table</button>
    <p class="help-block">Participants can continue only if every table has room for their group.
        Missing answers or answers outside your groups lead to the quota_full exit.</p>
</div>
<hr>
<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Quota"><?= $active ? 'Save description' : 'Save draft' ?></a>
    <?php if (!$active): ?><button type="button" class="btn btn-primary quota-activate">Save and activate</button><?php endif; ?>
</p>
<?php if (!$active): ?><p class="help-block">Save your provider settings in Complete before activating Quota.
    After activation, the categories and provider settings cannot be changed.
    Test the full run before inviting participants.</p><?php endif; ?>
