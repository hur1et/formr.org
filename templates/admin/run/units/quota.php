<?= $prepend ?>
<div class="alert alert-warning">Development version: validate the whole study before field use.</div>
<?php if ($active): ?><div class="alert alert-success">Activated. Category structure and provider settings are fixed.
    Reload the editor to update the Complete panel.</div><?php endif; ?>
<p>All tables must pass. Variables within a table are crossed automatically.
    Only explicit Complete records will count; admission reserves no place.</p>
<div class="quota-editor" data-readonly="<?= $active ? '1' : '0' ?>" data-sources="<?= h(json_encode($sources, JSON_THROW_ON_ERROR)) ?>">
    <input type="hidden" name="quota_tables" value="<?= h(json_encode((object) $tables, JSON_THROW_ON_ERROR)) ?>">
    <div class="quota-editor-error alert alert-danger" role="alert" hidden></div>
    <div class="quota-editor-tables"></div>
    <button type="button" class="btn btn-default quota-add-table">Add quota table</button>
    <p class="help-block">Whole numbers only, inclusive boundaries. New groups start at 0 (closed).
        Missing answers and values outside the selected categories will be excluded.</p>
</div>
<p><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Quota"><?= $active ? 'Save description' : 'Save draft' ?></a>
    <?php if (!$active): ?><button type="button" class="btn btn-primary quota-activate">Save and activate</button><?php endif; ?></p>
<?php if (!$active): ?><p>Before activation, save the Complete exit settings. Activation fixes the categories and starts
    quota checking at this run position. It does not reserve places.</p><?php endif; ?>
