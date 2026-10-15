<?= $prepend ?>
<div class="alert alert-warning">Development draft: participant execution and activation are not available yet.
    Place this unit after the saved demographic survey. Do not use it in a live study.</div>
<p>All tables must pass. Variables within a table are crossed automatically.
    Only explicit Complete records will count; admission reserves no place.</p>
<div class="quota-editor" data-sources="<?= h(json_encode($sources, JSON_THROW_ON_ERROR)) ?>">
    <input type="hidden" name="quota_tables" value="<?= h(json_encode((object) $tables, JSON_THROW_ON_ERROR)) ?>">
    <div class="quota-editor-error alert alert-danger" role="alert" hidden></div>
    <div class="quota-editor-tables"></div>
    <button type="button" class="btn btn-default quota-add-table">Add quota table</button>
    <p class="help-block">Whole numbers only, inclusive boundaries. New groups start at 0 (closed).
        Missing answers and values outside the selected categories will be excluded.</p>
</div>
<p><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Quota">Save draft</a></p>
