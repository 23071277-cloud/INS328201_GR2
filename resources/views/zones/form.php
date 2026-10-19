<?php
/** @var ?array $zone @var array $buildingOptions @var string $presetBuildingId @var array $form */
$isEdit = $zone !== null;
$breadcrumbs = [[t('menu.groups.catalog'), null], [t('menu.zones'), '/zones']];
if ($isEdit) {
    $breadcrumbs[] = [$zone['code'], '/zones/' . $zone['id']];
}
$breadcrumbs[] = [$isEdit ? t('common.actions.edit') : t('lit.add_new'), null];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$action = $isEdit ? '/zones/' . $zone['id'] . '/update' : '/zones';
$selectedBuilding = form_value($form, $zone, 'building_id', $presetBuildingId);
$selectedType = form_value($form, $zone, 'zone_type');
?>
<div class="page-head">
    <h1 class="page-title"><?= $isEdit ? t('lit.edit_zone') : t('lit.add_zone') ?></h1>
</div>

<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($action)) ?>" novalidate<?= $isEdit ? '' : ' data-zone-suggest' ?>>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="updated_at" value="<?= e(form_value($form, $zone, 'updated_at')) ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'building_id') ? 'has-error' : '' ?>">
                <label for="building_id"><?= t('menu.buildings') ?> <span class="required">*</span></label>
                <select id="building_id" name="building_id" required>
                    <option value=""><?= e(t('common.options.select_building')) ?></option>
                    <?php foreach ($buildingOptions as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" data-code="<?= e($b['code']) ?>" <?= $selectedBuilding === (string) $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['code'] . ' — ' . $b['name'] . ($b['status'] === 'ngung' ? t('lit.inactive') : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isEdit): ?><div class="form-hint"><?= t('ui.the_building_can_only_be_changed') ?></div><?php endif; ?>
                <?= field_error($form, 'building_id') ?>
            </div>
            <div class="form-group <?= has_error($form, 'zone_type') ? 'has-error' : '' ?>">
                <label for="zone_type"><?= e(t('common.fields.zone_type')) ?></label>
                <select id="zone_type" name="zone_type">
                    <option value=""><?= e(t('common.options.undetermined')) ?></option>
                    <?php foreach (master_labels('zone_type') as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedType === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($form, 'zone_type') ?>
            </div>
            <div class="form-group <?= has_error($form, 'code') ? 'has-error' : '' ?>">
                <label for="code"><?= t('ui.zone_code') ?> <span class="required">*</span></label>
                <input type="text" id="code" name="code" maxlength="30" required value="<?= e(form_value($form, $zone, 'code')) ?>" aria-describedby="zone_code_hint">
                <div class="form-hint" id="zone_code_hint"><?php if (!$isEdit): ?><?= t('ui.the_zone_code_is_suggested_from') ?> <?php endif; ?><?= t('ui.unique_within_the_building') ?></div>
                <?= field_error($form, 'code') ?>
            </div>
            <div class="form-group <?= has_error($form, 'name') ? 'has-error' : '' ?>">
                <label for="name"><?= t('ui.zone_name_2') ?> <span class="required">*</span></label>
                <input type="text" id="name" name="name" maxlength="150" required value="<?= e(form_value($form, $zone, 'name')) ?>" aria-describedby="zone_name_hint">
                <div class="form-hint" id="zone_name_hint"><?= t('ui.a_friendly_display_name_e_g') ?></div>
                <?= field_error($form, 'name') ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? t('lit.save_changes') : t('lit.add_zone') ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url($isEdit ? '/zones/' . $zone['id'] : '/zones')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
