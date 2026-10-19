<?php
/** @var array $filters @var array $rows */
$breadcrumbs = [[t('crumbs.alerts_fn07'), null], [t('page.alert_rules'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.alert_rules')) ?></h1>
        <p class="page-subtitle"><?= t('guide.alert_rules') ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('alert_rules.manage')): ?><a class="btn btn--primary" href="<?= e(base_url('/alert-rules/create')) ?>"><?= e(t('common.actions.add_rule')) ?></a><?php endif; ?>
    </div>
</div>
<div class="panel">
    <form method="get" action="<?= e(base_url('/alert-rules')) ?>" class="toolbar">
        <div class="form-group"><label for="status"><?= e(t('common.fields.status')) ?></label><select id="status" name="status"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach (config_labels('alerts.rule_status') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="utility_type"><?= e(t('common.fields.utility')) ?></label><select id="utility_type" name="utility_type"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach (master_labels('utility_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['utility_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="measure_type"><?= e(t('common.fields.measure_type')) ?></label><select id="measure_type" name="measure_type"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach (config_labels('alerts.measure_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['measure_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state"><p><?= e(t('landing.alert_rules.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th><?= e(t('common.fields.name')) ?></th><th><?= e(t('common.fields.condition')) ?></th><th><?= e(t('common.fields.scope')) ?></th><th><?= t('ui.assigned_priority') ?></th><th><?= e(t('common.fields.status')) ?></th><th class="num"><?= t('ui.alerts_generated') ?></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $r['status'] === 'tat' ? 'row-muted' : '' ?>">
                        <td><a href="<?= e(base_url('/alert-rules/' . $r['id'])) ?>">#<?= (int) $r['id'] ?></a></td>
                        <td><?= e($r['name']) ?></td>
                        <td><?= e(rule_condition_t('alert', $r)) ?></td>
                        <td><?= e(config_labels('alerts.rule_scope_type')[$r['scope_type']] ?? $r['scope_type']) ?><?= $r['scope_value'] !== null ? ': ' . e($r['scope_value']) : '' ?></td>
                        <td><?= e(config_labels('alerts.priority')[$r['priority_level']]) ?></td>
                        <td><?= $r['status'] === 'bat' ? '<span class="badge badge--active">' . e(config_label('alerts.rule_status', 'bat')) . '</span>' : '<span class="badge badge--inactive">' . e(config_label('alerts.rule_status', 'tat')) . '</span>' ?></td>
                        <td class="num"><a href="<?= e(url_with('/alerts', ['rule_id' => $r['id'], 'status' => 'all'])) ?>"><?= (int) $r['alert_count'] ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
