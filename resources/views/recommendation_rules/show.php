<?php
/** @var array $r */
$breadcrumbs = [[t('crumbs.alerts_recommendations'), null], [t('menu.recommendation_rules'), '/recommendation-rules'], ['#' . $r['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.recommendation_rule') ?><?= (int) $r['id'] ?> — <?= e($r['name']) ?></h1>
        <p class="page-subtitle"><?= $r['status'] === 'bat' ? '<span class="badge badge--active">' . e(config_label('recommendations.rule_status', 'bat')) . '</span>' : '<span class="badge badge--inactive">' . e(config_label('recommendations.rule_status', 'tat')) . '</span>' ?> <?= e(rule_condition_t('recommendation', $r)) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('recommendation_rules.manage')): ?>
            <a class="action-edit btn btn--ghost" href="<?= e(base_url('/recommendation-rules/' . $r['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
            <form method="post" action="<?= e(base_url('/recommendation-rules/' . $r['id'] . '/status')) ?>" class="inline-form js-confirm"
                  data-confirm-title="<?= $r['status'] === 'bat' ? t('lit.turn_rule_off') : t('lit.turn_rule_on') ?>" data-confirm-message="<?= $r['status'] === 'bat' ? t('lit.turn_off_not_used_for_new') : t('lit.turn_the_rule_on_for_the') ?>" data-confirm-button="<?= t('ui.ok') ?>">
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
        <div><dt><?= t('common.fields.condition') ?></dt><dd><?= e(rule_condition_t('recommendation', $r)) ?></dd></div>
        <div><dt><?= t('ui.parameters') ?></dt><dd><?= e($r['condition_params'] ?? '—') ?></dd></div>
        <div style="grid-column:1/-1"><dt><?= t('ui.recommendation_text') ?></dt><dd class="price-big"><?= e($r['recommendation_text']) ?></dd></div>
        <div><dt><?= t('ui.created_by_at_2') ?></dt><dd><?= e(($r['created_by_name'] ?? '—') . ' · ' . $r['created_at']) ?></dd></div>
    </dl>
    <p class="form-hint"><?= t('ui.the_consumption_0_condition_is_only') ?> <?= t('ui.for_cases_without_an_alert') ?></p>
</div>
