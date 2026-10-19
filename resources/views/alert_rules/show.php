<?php
/** @var array $r @var array $periods @var string $periodId @var ?array $dry */
$breadcrumbs = [[t('crumbs.alerts_fn07'), null], [t('menu.alert_rules'), '/alert-rules'], ['#' . $r['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Rule #<?= (int) $r['id'] ?> — <?= e($r['name']) ?></h1>
        <p class="page-subtitle"><?= $r['status'] === 'bat' ? '<span class="badge badge--active">' . e(config_label('alerts.rule_status', 'bat')) . '</span>' : '<span class="badge badge--inactive">' . e(config_label('alerts.rule_status', 'tat')) . '</span>' ?> <?= e(rule_condition_t('alert', $r)) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('alert_rules.manage')): ?>
            <a class="action-edit btn btn--ghost" href="<?= e(base_url('/alert-rules/' . $r['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
            <form method="post" action="<?= e(base_url('/alert-rules/' . $r['id'] . '/status')) ?>" class="inline-form js-confirm"
                  data-confirm-title="<?= $r['status'] === 'bat' ? t('lit.turn_rule_off') : t('lit.turn_rule_on') ?>" data-confirm-message="<?= $r['status'] === 'bat' ? t('lit.turn_the_rule_off_it_is') : t('lit.turn_the_rule_on_for_the_2') ?>" data-confirm-button="<?= t('ui.ok') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="version_token" value="<?= e($r['version_token']) ?>">
                <input type="hidden" name="status" value="<?= $r['status'] === 'bat' ? 'tat' : 'bat' ?>">
                <button type="submit" class="btn <?= $r['status'] === 'bat' ? 'btn--danger' : 'btn--primary' ?>"><?= $r['status'] === 'bat' ? t('lit.turn_off') : t('lit.turn_on') ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('common.fields.measure_type') ?></dt><dd><?= e(config_labels('alerts.measure_type')[$r['measure_type']]) ?></dd></div>
        <div><dt><?= t('common.fields.utility') ?></dt><dd><?= e(label_of('utility_type', $r['utility_type'])) ?></dd></div>
        <div><dt><?= t('ui.baseline_type') ?></dt><dd><?= e(config_labels('alerts.compare_base')[$r['compare_base']]) ?></dd></div>
        <div><dt><?= t('ui.threshold') ?></dt><dd class="price-big"><?= e(config('alerts.operator')[$r['operator']]) ?> <?= e(\App\Services\RuleConditionEvaluator::plain((string) $r['threshold_value'])) ?><?= $r['compare_base'] === 'gia_tri_tuyet_doi' ? '' : '%' ?></dd></div>
        <div><dt><?= t('common.fields.scope') ?></dt><dd><?= e(config_labels('alerts.rule_scope_type')[$r['scope_type']]) ?><?= $r['scope_value'] !== null ? ': ' . e($r['scope_value']) : '' ?></dd></div>
        <div><dt><?= t('ui.assigned_priority') ?></dt><dd><?= e(config_labels('alerts.priority')[$r['priority_level']]) ?></dd></div>
    </dl>
</div>
<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= t('ui.dry_run_on_a_period_no') ?></h2></div>
    <form method="get" action="<?= e(base_url('/alert-rules/' . $r['id'])) ?>" class="toolbar">
        <div class="form-group"><label for="period_id"><?= e(t('common.fields.period')) ?></label><select id="period_id" name="period_id"><?php foreach ($periods as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $periodId === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['period_code']) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn btn--ghost btn--sm"><?= t('ui.dry_run') ?></button>
    </form>
    <?php if ($dry !== null): ?>
        <p><?= t('ui.evaluate') ?> <?= (int) $dry['evaluated'] ?> <?= t('ui.entities') ?> <strong><?= count($dry['would_create']) ?></strong> <?= t('ui.cases_meet_the_condition') ?></p>
        <?php foreach ($dry['skipped'] as $reason => $n): ?><div class="form-hint"><?= e(message_t($reason)) ?>: <?= (int) $n ?></div><?php endforeach; ?>
        <?php if ($dry['would_create'] !== []): ?>
            <ul class="rule-list"><?php foreach ($dry['would_create'] as $c): ?><li><?= e($c['apartment_code'] . ($c['meter_code'] ? ' / ' . $c['meter_code'] : '')) ?>: <?= e(format_decimal($c['actual'])) ?><?= $c['percent'] !== null ? ' (' . e(pct_text($c['percent'])) . ')' : '' ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    <?php endif; ?>
</div>
