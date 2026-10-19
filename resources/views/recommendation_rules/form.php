<?php
/** @var ?array $r @var array $form */
$isEdit = $r !== null;
$breadcrumbs = [[t('crumbs.alerts_recommendations'), null], [t('menu.recommendation_rules'), '/recommendation-rules'], [$isEdit ? t('lit.edit') . $r['id'] : t('lit.add'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$rec = $isEdit ? ['name' => $r['name'], 'condition_type' => $r['condition_type'], 'n_periods' => (string) ($r['params']['n_periods'] ?? ''), 'utility_type' => (string) ($r['params']['utility_type'] ?? ''), 'recommendation_text' => $r['recommendation_text']] : null;
$v = fn (string $k, string $d = '') => form_value($form, $rec, $k, $d);
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= $isEdit ? t('lit.edit_recommendation_rule') . (int) $r['id'] : t('lit.add_recommendation_rule') ?></h1>
        <p class="page-subtitle"><?= t('ui.write_the_text_as_a') ?> <strong><?= t('ui.suggestion_to_consider') ?></strong> <?= t('ui.e_g_re_check_the_meter') ?> <?= e(implode(' ', config('recommendations.placeholders'))) ?>.</p>
    </div>
</div>
<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($isEdit ? '/recommendation-rules/' . $r['id'] . '/update' : '/recommendation-rules')) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="version_token" value="<?= e($r['version_token']) ?>"><?php endif; ?>
        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'name') ? 'has-error' : '' ?>"><label for="name"><?= t('ui.rule_name') ?> <span class="required">*</span></label><input id="name" name="name" maxlength="150" value="<?= e($v('name')) ?>"><?= field_error($form, 'name') ?></div>
            <div class="form-group <?= has_error($form, 'condition_type') ? 'has-error' : '' ?>"><label for="condition_type"><?= t('common.fields.condition') ?> <span class="required">*</span></label>
                <select id="condition_type" name="condition_type"><option value=""><?= e(t('common.options.select')) ?></option><?php foreach (config_labels('recommendations.condition_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('condition_type') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= field_error($form, 'condition_type') ?></div>
            <div class="form-group <?= has_error($form, 'n_periods') ? 'has-error' : '' ?>"><label for="n_periods"><?= t('ui.n_consecutive_periods_first_2_types') ?></label><input id="n_periods" name="n_periods" inputmode="numeric" value="<?= e($v('n_periods')) ?>"><?= field_error($form, 'n_periods') ?></div>
            <div class="form-group <?= has_error($form, 'utility_type') ? 'has-error' : '' ?>"><label for="utility_type"><?= t('ui.only_for_utility_optional') ?></label>
                <select id="utility_type" name="utility_type"><option value=""><?= e(t('common.options.all_utilities')) ?></option><?php foreach (master_labels('utility_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('utility_type') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= field_error($form, 'utility_type') ?></div>
            <div class="form-group <?= has_error($form, 'recommendation_text') ? 'has-error' : '' ?>" style="grid-column:1/-1"><label for="recommendation_text"><?= t('ui.recommendation_text') ?> <span class="required">*</span></label>
                <textarea id="recommendation_text" name="recommendation_text" rows="3" maxlength="500"><?= e($v('recommendation_text')) ?></textarea><?= field_error($form, 'recommendation_text') ?></div>
        </div>
        <?php if ($isEdit && (int) ($r['used_count'] ?? 0) > 0): ?><p class="form-hint"><?= t('ui.this_rule_has_been_used_generated') ?></p><?php endif; ?>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= e(t('common.actions.save')) ?></button><a class="btn btn--secondary" href="<?= e(base_url($isEdit ? '/recommendation-rules/' . $r['id'] : '/recommendation-rules')) ?>"><?= e(t('common.cancel')) ?></a></div>
    </form>
</div>
