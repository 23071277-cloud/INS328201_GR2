<?php
/** @var array $filters @var array $periods @var array $result */
$breadcrumbs = [[t('crumbs.baseline_targets'), null], [t('lit.targets'), '/targets'], [t('lit.attainment_tracking'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$units = config('pricing.utility_unit');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.target_attainment_tracking') ?></h1>
        <p class="page-subtitle"><?= t('guide.targets_tracking') ?></p>
    </div>
</div>
<div class="panel">
    <form method="get" action="<?= e(base_url('/targets/tracking')) ?>" class="toolbar">
        <div class="form-group"><label for="period_id"><?= e(t('common.fields.period')) ?></label>
            <select id="period_id" name="period_id"><?php foreach ($periods as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $filters['period_id'] === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['period_code']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="scope_type"><?= e(t('common.fields.scope')) ?></label>
            <select id="scope_type" name="scope_type"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach (config('analytics.target_scope_types') as $v): ?><option value="<?= e($v) ?>" <?= $filters['scope_type'] === $v ? 'selected' : '' ?>><?= e(config_labels('analytics.scope_type')[$v]) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="utility_type"><?= e(t('common.fields.utility')) ?></label>
            <select id="utility_type" name="utility_type"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach (master_labels('utility_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['utility_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn btn--primary btn--sm">Xem</button>
    </form>

    <?php if ($result['summary'] !== []): ?>
        <div class="card-grid">
            <?php foreach ($result['summary'] as $s): ?>
                <div class="stat-card">
                    <div class="stat-card__label"><?= t('ui.attainment_rate') ?> <?= e(config_labels('analytics.scope_type')[$s['scope_type']]) ?>, <?= e(label_of('utility_type', $s['utility_type'])) ?></div>
                    <div class="stat-card__value"><?= e($s['attainment_rate'] === null ? 'N/A' : format_decimal($s['attainment_rate']) . '%') ?></div>
                    <div class="form-hint"><?= (int) $s['dat'] ?> <?= t('ui.met') ?> <?= (int) $s['tracked'] ?> <?= t('ui.tracked') ?><?= $s['chua_du_du_lieu'] ? ' · ' . (int) $s['chua_du_du_lieu'] . t('lit.with_insufficient_data_not_counted') : '' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($result['rows'] === []): ?>
        <div class="empty-state"><p><?= t('ui.no_targets_for_this_period_yet') ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.scope')) ?></th><th><?= e(t('common.fields.utility')) ?></th><th class="num"><?= e(t('common.fields.target')) ?></th><th class="num"><?= e(t('common.fields.actual')) ?></th><th class="num"><?= e(t('common.fields.difference')) ?></th><th class="num"><?= t('ui.completion') ?></th><th><?= e(t('common.fields.result')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $t): $k = $t['tracking']; ?>
                    <tr>
                        <td><?= e(config_labels('analytics.scope_type')[$t['scope_type']]) ?>: <?= e($t['scope_label']) ?></td>
                        <td><?= e(label_of('utility_type', $t['utility_type'])) ?></td>
                        <td class="num"><?= e(format_decimal($k['target_value'])) ?> <?= e($units[$t['utility_type']]) ?></td>
                        <td class="num"><?= $k['actual_value'] === null ? '—' : e(format_decimal($k['actual_value'])) ?></td>
                        <td class="num"><?= $k['variance'] === null ? '—' : e(format_decimal($k['variance'])) ?></td>
                        <td class="num"><?= e($k['actual_value'] === null ? '—' : ($k['achievement_percentage'] === null ? t('lit.n_a_target_0') : format_decimal($k['achievement_percentage']) . '%')) ?></td>
                        <td><?= target_badge($k['target_status']) ?><?= $k['target_status'] === 'chua_du_du_lieu' ? '<div class="form-hint">' . e(config_labels('analytics.data_status')[$k['data_status']] ?? '') . '</div>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
