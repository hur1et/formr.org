<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= h($monitor['name']) ?> — Quotas</title>
    <style>
        body { margin: 0; color: #333; background: #fff; font: 14px/1.43 "Helvetica Neue", Helvetica, Arial, sans-serif; }
        main { max-width: 1100px; margin: 30px auto; padding: 0 15px; }
        h1 { font-size: 28px; font-weight: 400; } h2 { font-size: 20px; font-weight: 400; }
        .table-responsive { overflow-x: auto; } table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; vertical-align: middle; }
        tbody tr:nth-child(odd) { background: #f9f9f9; } td.success { background: #dff0d8; }
        code { white-space: nowrap; } .updated { color: #666; }
    </style>
</head>
<body>
<main>
    <h1><?= h($monitor['name']) ?> — Quotas</h1>
    <p>Completed participants: <?= (int)$monitor['completed'] ?></p>
    <p class="updated">Updated: <?= h(gmdate('Y-m-d H:i:s')) ?> UTC. Reload this page to see the latest counts.</p>
    <?php if (!$monitor['groups']): ?><p>No quota groups have been set up yet.</p><?php endif; ?>
    <?php foreach ($monitor['groups'] as $table=>$groups): ?>
        <h2><?= h($table) ?></h2>
        <div class="table-responsive">
            <table>
                <thead><tr><th scope="col">Code</th><th scope="col">Quota group</th><th scope="col">Limit</th><th scope="col">Count</th><th scope="col">Fulfillment</th></tr></thead>
                <tbody>
                    <?php foreach ($groups as $code=>$group):
                        $percentage=$group['limit']>0 ? rtrim(rtrim(number_format($group['count']/$group['limit']*100,1,'.',''),'0'),'.').' %' : '—'; ?>
                        <tr>
                            <td><code><?= h($code) ?></code></td>
                            <td><?php foreach ($group['labels'] as $label): ?><div><?= h($label) ?></div><?php endforeach; ?></td>
                            <td><?= (int)$group['limit'] ?></td>
                            <td><?= (int)$group['count'] ?></td>
                            <td<?= $group['limit']>0 && $group['count']>=$group['limit'] ? ' class="success"' : '' ?>><?= h($percentage) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</main>
</body>
</html>
