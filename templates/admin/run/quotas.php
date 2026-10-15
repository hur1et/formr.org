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
                        <?php if (!$quota_overview['groups']): ?>
                            <p class="help-block">Use Quota to set limits for groups of participants.
                                <a href="<?= admin_run_url($run->name) ?>">Set up quota groups in Edit Run.</a></p>
                        <?php else: ?>
                            <p class="quota-completed">Completed participants: <?= (int)$quota_overview['completed'] ?></p>
                            <?php if ($quota_overview['quota_id']): ?>
                                <p class="help-block">Count changes are logged automatically with your account, the time and the amount changed.</p>
                            <?php endif; ?>
                            <form method="post" action="<?= admin_run_url($run->name,'quotas') ?>">
                                <input type="hidden" name="cohort" value="<?= h($cohort) ?>">
                                <input type="hidden" name="quota_id" value="<?= (int)$quota_overview['quota_id'] ?>">
                                <input type="hidden" name="definition_hash" value="<?= h($posted['definition_hash']??$quota_overview['definition_hash']) ?>">
                                <input type="hidden" name="operation_key" value="<?= h($operation_key) ?>">
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
                                                    $prefix='rows['.$table.']['.$code.']';
                                                    $name=$table.': '.$code;
                                                    $fieldId='quota-group-'.++$quotaFieldIndex;
                                                    $percentage=$group['limit']>0 ? rtrim(rtrim(number_format($group['count']/$group['limit']*100,1,'.',''),'0'),'.').' %' : '—'; ?>
                                                    <tr>
                                                        <td><code><?= h($code) ?></code></td>
                                                        <td><?php foreach ($group['labels'] as $label): ?><div><?= h($label) ?></div><?php endforeach; ?></td>
                                                        <td>
                                                            <input type="hidden" name="<?= h($prefix) ?>[base_limit]" value="<?= h($row['base_limit']??$group['limit']) ?>">
                                                            <label class="sr-only" id="<?= $fieldId ?>-limit-label" for="<?= $fieldId ?>-limit"><?= h('Limit for '.$name) ?></label>
                                                            <input class="form-control input-sm quota-number" id="<?= $fieldId ?>-limit" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="10" required name="<?= h($prefix) ?>[limit]" value="<?= h($row['limit']) ?>">
                                                        </td>
                                                        <td>
                                                            <input type="hidden" name="<?= h($prefix) ?>[base_count]" value="<?= h($row['base_count']??$group['count']) ?>">
                                                            <?php if ($quota_overview['quota_id']): ?>
                                                                <label class="sr-only" id="<?= $fieldId ?>-count-label" for="<?= $fieldId ?>-count"><?= h('Count for '.$name) ?></label>
                                                                <input class="form-control input-sm quota-number" id="<?= $fieldId ?>-count" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="19" required name="<?= h($prefix) ?>[count]" value="<?= h($row['count']) ?>">
                                                            <?php else: ?>
                                                                0<input type="hidden" name="<?= h($prefix) ?>[count]" value="0">
                                                            <?php endif; ?>
                                                        </td>
                                                        <td<?= $group['limit']>0 && $group['count']>=$group['limit'] ? ' class="success"' : '' ?>><?= h($percentage) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endforeach; ?>
                                <button class="btn btn-primary" type="submit">Save</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php Template::loadChild('admin/footer'); ?>
