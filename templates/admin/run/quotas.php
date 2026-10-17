<?php Template::loadChild('admin/header'); ?>
<div class="content-wrapper">
    <section class="content-header"><h1><?= h($run->name) ?></h1></section>
    <section class="content">
        <div class="row">
            <div class="col-md-2"><?php Template::loadChild('admin/run/menu'); ?></div>
            <div class="col-md-10">
                <div class="box box-primary quota-overview">
                    <div class="box-header with-border"><h3 class="box-title">Quotas</h3></div>
                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <form method="get" action="<?= admin_run_url($run->name,'quotas') ?>" class="form-inline">
                            <div class="form-group">
                                <label for="quota-cohort">Participants</label>
                                <select name="cohort" id="quota-cohort" class="form-control" onchange="this.form.submit()">
                                    <option value="field" <?= $cohort==='field'?'selected':'' ?>>Field participants</option>
                                    <option value="test" <?= $cohort==='test'?'selected':'' ?>>Test participants</option>
                                </select>
                            </div>
                            <a class="btn btn-default" href="<?= admin_run_url($run->name,'quotas?cohort='.$cohort) ?>">Refresh</a>
                        </form>
                        <p class="help-block">Test counts are separate; limits apply to both field and test participants.
                            Refresh discards unsaved edits.</p>
                        <?php if (!$quota_overview['groups']): ?>
                            <p class="help-block">No quota groups yet.
                                <a href="<?= admin_run_url($run->name) ?>">Add and configure Quota in Edit Run.</a></p>
                        <?php else: ?>
                            <p class="quota-completed">Completed participants: <?= (int)$quota_overview['completed'] ?></p>
                            <?php if ($quota_overview['quota_id']): ?>
                                <p class="help-block">To correct a count, change it and choose Save. New completions since you opened this page are kept.
                                    Changes are recorded with your account, the time and the amount changed.</p>
                            <?php endif; ?>
                            <form method="post" action="<?= admin_run_url($run->name,'quotas') ?>" class="quota-overview-form">
                                <input type="hidden" name="cohort" value="<?= h($cohort) ?>">
                                <input type="hidden" name="quota_id" value="<?= (int)$quota_overview['quota_id'] ?>">
                                <input type="hidden" name="definition_hash" value="<?= h($posted['definition_hash']??$quota_overview['definition_hash']) ?>">
                                <input type="hidden" name="operation_key" value="<?= h($operation_key) ?>">
                                <input type="hidden" name="rows_json" value="">
                                <?php $quotaFieldIndex=0; foreach ($quota_overview['groups'] as $table=>$groups): ?>
                                    <h4><?= h($table) ?></h4>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped table-condensed">
                                            <thead><tr><th>Code</th><th>Quota group</th><th>Limit</th><th>Count</th><th>Fulfillment</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($groups as $code=>$group):
                                                    $input=$posted['rows'][$table][$code]??[];
                                                    $row=$group;
                                                    foreach (['limit','count','base_limit','base_count'] as $field) {
                                                        if (is_array($input) && isset($input[$field]) && is_scalar($input[$field])) { $row[$field]=$input[$field]; }
                                                    }
                                                    $name=$table.': '.$code;
                                                    $fieldId='quota-group-'.++$quotaFieldIndex;
                                                    $percentage=$group['limit']>0 ? rtrim(rtrim(number_format($group['count']/$group['limit']*100,1,'.',''),'0'),'.').' %' : '—'; ?>
                                                    <tr data-quota-table="<?= h($table) ?>" data-quota-group="<?= h($code) ?>">
                                                        <td><code><?= h($code) ?></code></td>
                                                        <td><?php foreach ($group['labels'] as $label): ?><div><?= h($label) ?></div><?php endforeach; ?></td>
                                                        <td>
                                                            <input type="hidden" data-quota-field="base_limit" value="<?= h($row['base_limit']??$group['limit']) ?>">
                                                            <label class="sr-only" id="<?= $fieldId ?>-limit-label" for="<?= $fieldId ?>-limit"><?= h('Limit for '.$name) ?></label>
                                                            <input class="form-control input-sm quota-number" id="<?= $fieldId ?>-limit" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="10" required data-quota-field="limit" value="<?= h($row['limit']) ?>">
                                                        </td>
                                                        <td>
                                                            <input type="hidden" data-quota-field="base_count" value="<?= h($row['base_count']??$group['count']) ?>">
                                                            <?php if ($quota_overview['quota_id']): ?>
                                                                <label class="sr-only" id="<?= $fieldId ?>-count-label" for="<?= $fieldId ?>-count"><?= h('Count for '.$name) ?></label>
                                                                <input class="form-control input-sm quota-number" id="<?= $fieldId ?>-count" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="19" required data-quota-field="count" value="<?= h($row['count']) ?>">
                                                            <?php else: ?>
                                                                0<input type="hidden" data-quota-field="count" value="0">
                                                            <?php endif; ?>
                                                        </td>
                                                        <td<?= $group['limit']>0 && $group['count']>=$group['limit'] ? ' class="success"' : '' ?>><?= h($percentage) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endforeach; ?>
                                <button class="btn btn-primary" type="submit" disabled>Save</button>
                                <noscript><p>Enable JavaScript to save quota changes.</p></noscript>
                            </form>
                        <?php endif; ?>
                        <hr>
                        <h4>Provider statistics</h4>
                        <p class="help-block">Share a table of current field counts with your panel provider.
                            Anyone with the link can view it without signing in, but cannot change it.</p>
                        <?php if ($provider_link!==null): ?>
                            <div class="form-group">
                                <label for="quota-provider-link">Provider link</label>
                                <input id="quota-provider-link" class="form-control" type="text" readonly value="<?= h($provider_link) ?>">
                            </div>
                            <p><a href="<?= h($provider_link) ?>" target="_blank" rel="noopener noreferrer">Open provider statistics</a></p>
                        <?php endif; ?>
                        <form method="post" action="<?= admin_run_url($run->name,'quota_share') ?>">
                            <button class="btn btn-default" name="share_action" value="<?= $provider_link===null?'enable':'revoke' ?>" type="submit"><?= $provider_link===null?'Create provider link':'Revoke provider link' ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php Template::loadChild('admin/footer'); ?>
