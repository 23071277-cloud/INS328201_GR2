<?php
/** @var array $filters @var array $rows */
$breadcrumbs = [[t('crumbs.alerts_recommendations'), null], [t('page.recommendation_rules'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.recommendation_rules')) ?></h1>
        <p class="page-subtitle"><?= t('guide.rec_rules') ?></p>
    </div>
    <div class="page-head__actions"><?php if (can('recommendation_rules.manage')): ?><a class="btn btn--primary" href="<?= e(base_url('/recommendation-rules/create')) ?>"><?= e(t('common.actions.add_rule')) ?></a><?php endif; ?></div>
</div>
<div class="panel">
    <form method="get" action="<?= e(base_url('/recommendation-rules')) ?>" class="toolbar">
        <div class="form-group"><label for="status"><?= e(t('common.fields.status')) ?></label><select id="status" name="status"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach (config_labels('recommendations.rule_status') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="condition_type"><?= e(t('common.fields.condition')) ?></label><select id="condition_type" name="condition_type"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach (config_labels('recommendations.condition_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['condition_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state"><p><?= e(t('landing.recommendation_rules.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>#</th><th><?= e(t('common.fields.name')) ?></th><th><?= e(t('common.fields.condition')) ?></th><th><?= t('ui.recommendation_text') ?></th><th><?= e(t('common.fields.status')) ?></th><th class="num"><?= t('ui.used') ?></th><th><?= t('ui.created_by_at_2') ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= $r['status'] === 'tat' ? 'row-muted' : '' ?>">
                    <td><a href="<?= e(base_url('/recommendation-rules/' . $r['id'])) ?>">#<?= (int) $r['id'] ?></a></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e(rule_condition_t('recommendation', $r)) ?></td>
                    <td><?= e($r['recommendation_text']) ?></td>
                    <td><?= $r['status'] === 'bat' ? '<span class="badge badge--active">' . e(config_label('recommendations.rule_status', 'bat')) . '</span>' : '<span class="badge badge--inactive">' . e(config_label('recommendations.rule_status', 'tat')) . '</span>' ?></td>
                    <td class="num"><?= (int) $r['used_count'] ?></td>
                    <td><?= e(($r['created_by_name'] ?? '—') . ' · ' . $r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
