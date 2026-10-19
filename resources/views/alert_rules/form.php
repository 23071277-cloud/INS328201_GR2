<?php
/** @var ?array $r @var array $form @var array $zones */
$isEdit = $r !== null;
$breadcrumbs = [[t('crumbs.alerts_fn07'), null], [t('menu.alert_rules'), '/alert-rules'], [$isEdit ? t('lit.edit') . $r['id'] : t('lit.add'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$rec = $isEdit ? $r + ['threshold_value' => \App\Services\RuleConditionEvaluator::plain((string) $r['threshold_value'])] : null;
$val = fn (string $k, string $d = '') => form_value($form, $rec, $k, $d);
$scopeType = $val('scope_type', 'toan_toa');
$scopeValue = (string) form_value($form, $rec, 'scope_value');
$select = function (string $name, array $options, string $current): string {
    $html = '<select id="' . e($name) . '" name="' . e($name) . '">';
    foreach ($options as $v => $l) {
        $html .= '<option value="' . e((string) $v) . '"' . ((string) $v === $current ? ' selected' : '') . '>' . e($l) . '</option>';
    }

    return $html . '</select>';
};
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= $isEdit ? t('lit.edit_rule') . (int) $r['id'] : t('lit.add_threshold_rule') ?></h1>
        <p class="page-subtitle"><?= t('guide.alert_rule_form') ?></p>
    </div>
</div>
<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($isEdit ? '/alert-rules/' . $r['id'] . '/update' : '/alert-rules')) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="version_token" value="<?= e($r['version_token']) ?>"><?php endif; ?>
        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'name') ? 'has-error' : '' ?>"><label for="name"><?= t('ui.rule_name') ?> <span class="required">*</span></label><input id="name" name="name" maxlength="150" value="<?= e($val('name')) ?>"><?= field_error($form, 'name') ?></div>
            <div class="form-group <?= has_error($form, 'measure_type') ? 'has-error' : '' ?>"><label for="measure_type"><?= e(t('common.fields.measure_type')) ?></label><?= $select('measure_type', config_labels('alerts.measure_type'), $val('measure_type', 'tieu_thu')) ?><?= field_error($form, 'measure_type') ?></div>
            <div class="form-group <?= has_error($form, 'utility_type') ? 'has-error' : '' ?>"><label for="utility_type"><?= e(t('common.fields.utility')) ?></label><?= $select('utility_type', master_labels('utility_type'), $val('utility_type', 'dien')) ?><?= field_error($form, 'utility_type') ?></div>
            <div class="form-group <?= has_error($form, 'compare_base') ? 'has-error' : '' ?>"><label for="compare_base"><?= t('ui.comparison_baseline') ?></label><?= $select('compare_base', config_labels('alerts.compare_base'), $val('compare_base', 'baseline')) ?><?= field_error($form, 'compare_base') ?></div>
            <div class="form-group <?= has_error($form, 'operator') ? 'has-error' : '' ?>"><label for="operator"><?= t('ui.operator') ?></label><?= $select('operator', config('alerts.operator'), $val('operator', 'gt')) ?><?= field_error($form, 'operator') ?></div>
            <div class="form-group <?= has_error($form, 'threshold_value') ? 'has-error' : '' ?>"><label for="threshold_value"><?= t('ui.threshold') ?> <span class="required">*</span></label><input id="threshold_value" name="threshold_value" inputmode="decimal" value="<?= e($val('threshold_value')) ?>"><?= field_error($form, 'threshold_value') ?></div>
            <div class="form-group <?= has_error($form, 'scope_type') ? 'has-error' : '' ?>"><label for="scope_type"><?= e(t('common.fields.scope')) ?></label><?= $select('scope_type', config_labels('alerts.rule_scope_type'), $scopeType) ?><?= field_error($form, 'scope_type') ?></div>
            <div class="form-group <?= has_error($form, 'scope_value') ? 'has-error' : '' ?>"><label for="scope_zone"><?= t('ui.zone_when_scope_zone') ?></label>
                <select id="scope_zone" name="scope_zone"><option value="">—</option><?php foreach ($zones as $z): ?><option value="<?= (int) $z['id'] ?>" <?= $scopeType === 'zone' && $scopeValue === (string) $z['id'] ? 'selected' : '' ?>><?= e($z['label']) ?></option><?php endforeach; ?></select>
                <div class="form-hint"><?= t('ui.follows_the') ?> <strong><?= t('ui.current') ?></strong> <?= t('ui.zone_master_data_zone_changes_are') ?></div><?= field_error($form, 'scope_value') ?></div>
            <div class="form-group"><label for="scope_usage"><?= t('ui.usage_type_when_scope_usage_type') ?></label>
                <select id="scope_usage" name="scope_usage"><option value="">—</option><?php foreach (master_labels('usage_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $scopeType === 'usage_type' && $scopeValue === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="form-group <?= has_error($form, 'priority_level') ? 'has-error' : '' ?>"><label for="priority_level"><?= t('ui.assigned_priority_level') ?></label><?= $select('priority_level', config_labels('alerts.priority'), $val('priority_level', 'trung_binh')) ?><div class="form-hint"><?= t('ui.official_priority_criteria_j_25') ?></div><?= field_error($form, 'priority_level') ?></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= e(t('common.actions.save')) ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url($isEdit ? '/alert-rules/' . $r['id'] : '/alert-rules')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
