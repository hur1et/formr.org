<?= $prepend ?>
<?php if ($active): ?>
    <div class="alert alert-info" role="status"><strong>Quotas are active.</strong>
        To edit tables, variables, categories or provider settings, reopen the configuration in Complete.
        Change limits and counts under <a href="<?= h($overviewUrl) ?>">Quotas</a> in the run menu.</div>
<?php endif; ?>
<?php if (!empty($reopened)): ?><div class="alert alert-info" role="status">Quota configuration is open for editing.
    Current rules and counts remain in use until you activate the changes in Complete.</div><?php endif; ?>
<p>Set limits for groups of participants. Only those who reach Complete are counted.</p>
<p>Combine variables in one table to cross them, or add tables for separate targets.</p>
<fieldset class="quota-editor" <?= $active ? 'disabled' : '' ?> data-max-groups="<?= QuotaRules::MAX_GROUPS ?>" data-readonly="<?= $active ? '1' : '0' ?>" data-sources="<?= h(json_encode($sources, JSON_THROW_ON_ERROR)) ?>">
    <input type="hidden" name="quota_tables" value="<?= h(json_encode((object) $tables, JSON_THROW_ON_ERROR)) ?>">
    <div class="quota-editor-error alert alert-danger" role="alert" hidden></div>
    <div class="quota-editor-tables"></div>
    <button type="button" class="btn btn-default btn-sm quota-add-table">Add quota table</button>
    <p class="help-block">Participants can continue only if every table has room for their group.
        Missing answers or answers outside your groups lead to the quota_full exit.</p>
</fieldset>
<hr>
<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Quota"><?= $active ? 'Save description' : 'Save' ?></a>
</p>
<?php if (!$active): ?><p class="help-block">Save your quota tables, then add Complete after the main questionnaire.
    Activate both modules in Complete before opening the run to participants. A panel provider is optional.</p><?php endif; ?>
