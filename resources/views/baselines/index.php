<?php
/** @var array $filters @var array $rows */
$breadcrumbs = [[t('crumbs.baseline_targets'), null], [t('crumbs.baseline'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$units = config('pricing.utility_unit');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.baselines')) ?></h1>
        <p class="page-subtitle"><?= t('guide.baselines') ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('baselines.manage')): ?><a class="btn btn--primary" href="<?= e(base_url('/baselines/create')) ?>"><?= t('ui.create_baseline') ?></a><?php endif; ?>
    </div>
</div>

<div class="panel">
    <form method="get" action="<?= e(base_url('/baselines')) ?>" class="toolbar">
        <div class="form-group">
            <label for="scope_type"><?= e(t('common.fields.scope')) ?></label>
            <select id="scope_type" name="scope_type"><option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (config_labels('analytics.scope_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['scope_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="utility_type"><?= e(t('common.fields.utility')) ?></label>
            <select id="utility_type" name="utility_type"><option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('utility_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['utility_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="method"><?= e(t('common.fields.method')) ?></label>
            <select id="method" name="method"><option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (config_labels('analytics.baseline_method') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['method'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/baselines')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state"><p><?= e(t('landing.baselines.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th><?= e(t('common.fields.scope')) ?></th><th><?= e(t('common.fields.utility')) ?></th><th><?= e(t('common.fields.method')) ?></th><th class="num"><?= e(t('common.fields.value')) ?></th><th><?= e(t('common.fields.effective_from')) ?></th><th><?= t('ui.lifecycle') ?></th><th><?= t('ui.created_by') ?></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $b): ?>
                    <tr class="<?= $b['lifecycle'] === 'dang_ap_dung' ? 'row-current' : ($b['lifecycle'] === 'het_hieu_luc' ? 'row-muted' : '') ?>">
                        <td><a href="<?= e(base_url('/baselines/' . $b['id'])) ?>">#<?= (int) $b['id'] ?></a></td>
                        <td><?= e(config_labels('analytics.scope_type')[$b['scope_type']]) ?>: <?= e($b['scope_label']) ?></td>
                        <td><?= e(label_of('utility_type', $b['utility_type'])) ?></td>
                        <td><?= e(config_labels('analytics.baseline_method')[$b['method']] ?? $b['method']) ?><?= $b['n_periods'] ? ' (N = ' . (int) $b['n_periods'] . ')' : '' ?></td>
                        <td class="num"><?= $b['value_cents'] === null ? '—' : e(format_decimal(\App\Core\Decimal::fromCents($b['value_cents']))) . ' ' . e($units[$b['utility_type']]) ?></td>
                        <td><?= e(format_date($b['effective_date'])) ?></td>
                        <td><?= e(config_labels('analytics.baseline_lifecycle')[$b['lifecycle']] ?? $b['lifecycle']) ?></td>
                        <td><?= e($b['created_by_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
