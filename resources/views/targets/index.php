<?php
/** @var array $filters @var array $periods @var array $rows */
$breadcrumbs = [[t('crumbs.baseline_targets'), null], [t('page.targets'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$units = config('pricing.utility_unit');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.targets')) ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars(t('landing.targets.subtitle'), ENT_NOQUOTES, 'UTF-8') ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url_with('/targets/tracking', ['period_id' => $filters['period_id']])) ?>"><?= t('ui.track_attainment') ?></a>
        <?php if (can('targets.manage')): ?><a class="btn btn--primary" href="<?= e(base_url('/targets/create')) ?>"><?= t('ui.set_target') ?></a><?php endif; ?>
    </div>
</div>
<div class="panel">
    <form method="get" action="<?= e(base_url('/targets')) ?>" class="toolbar">
        <div class="form-group"><label for="period_id"><?= e(t('common.fields.period')) ?></label>
            <select id="period_id" name="period_id"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach ($periods as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $filters['period_id'] === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['period_code']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="scope_type"><?= e(t('common.fields.scope')) ?></label>
            <select id="scope_type" name="scope_type"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach (config('analytics.target_scope_types') as $v): ?><option value="<?= e($v) ?>" <?= $filters['scope_type'] === $v ? 'selected' : '' ?>><?= e(config_labels('analytics.scope_type')[$v]) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="utility_type"><?= e(t('common.fields.utility')) ?></label>
            <select id="utility_type" name="utility_type"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach (master_labels('utility_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['utility_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state"><p><?= e(t('landing.targets.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.period')) ?></th><th><?= e(t('common.fields.scope')) ?></th><th><?= e(t('common.fields.utility')) ?></th><th class="num"><?= e(t('common.fields.target')) ?></th><th class="num"><?= e(t('common.fields.actual')) ?></th><th><?= e(t('common.fields.result')) ?></th><th><?= t('ui.set_by') ?></th><th class="actions"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $t): ?>
                    <tr>
                        <td><?= e($t['period_code']) ?></td>
                        <td><?= e(config_labels('analytics.scope_type')[$t['scope_type']]) ?>: <?= e($t['scope_label']) ?></td>
                        <td><?= e(label_of('utility_type', $t['utility_type'])) ?></td>
                        <td class="num"><?= e(format_decimal($t['target_value'])) ?> <?= e($units[$t['utility_type']]) ?></td>
                        <td class="num"><?= $t['tracking']['actual_value'] === null ? '—' : e(format_decimal($t['tracking']['actual_value'])) ?></td>
                        <td><?= target_badge($t['tracking']['target_status']) ?></td>
                        <td><?= e($t['created_by_name'] ?? '—') ?></td>
                        <td class="actions"><?php if (can('targets.manage')): ?><a class="action-edit" href="<?= e(base_url('/targets/' . $t['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
