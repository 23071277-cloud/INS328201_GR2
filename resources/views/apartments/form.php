<?php
/**
 * @var ?array $apartment @var array $buildingOptions @var array $zoneOptions
 * @var bool $buildingLocked @var string $presetBuildingId @var array $form
 */
$isEdit = $apartment !== null;
$breadcrumbs = [[t('menu.groups.catalog'), null], [t('menu.apartments'), '/apartments']];
if ($isEdit) {
    $breadcrumbs[] = [$apartment['code'], '/apartments/' . $apartment['id']];
}
$breadcrumbs[] = [$isEdit ? t('common.actions.edit') : t('lit.add_new'), null];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$action = $isEdit ? '/apartments/' . $apartment['id'] . '/update' : '/apartments';
$selectedBuilding = form_value($form, $apartment, 'building_id', $presetBuildingId);
$selectedZone = form_value($form, $apartment, 'zone_id');
$selectedOccupancy = form_value($form, $apartment, 'occupancy_status', 'co_o');
?>
<div class="page-head">
    <h1 class="page-title"><?= $isEdit ? t('lit.edit_apartment') : t('lit.add_apartment') ?></h1>
</div>

<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($action)) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="updated_at" value="<?= e(form_value($form, $apartment, 'updated_at')) ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'building_id') ? 'has-error' : '' ?>">
                <label for="building_id"><?= t('menu.buildings') ?> <span class="required">*</span></label>
                <select id="building_id" name="building_id" required data-zone-filter-source <?= $buildingLocked ? 'disabled' : '' ?>>
                    <option value=""><?= e(t('common.options.select_building')) ?></option>
                    <?php foreach ($buildingOptions as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= $selectedBuilding === (string) $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['code'] . ' — ' . $b['name'] . ($b['status'] === 'ngung' ? t('lit.inactive') : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($buildingLocked): ?>
                    <input type="hidden" name="building_id" value="<?= e($selectedBuilding) ?>">
                    <div class="form-hint"><?= t('ui.the_building_cannot_be_changed_because') ?></div>
                <?php endif; ?>
                <?= field_error($form, 'building_id') ?>
            </div>
            <div class="form-group <?= has_error($form, 'zone_id') ? 'has-error' : '' ?>">
                <label for="zone_id"><?= e(t('common.fields.zone')) ?></label>
                <select id="zone_id" name="zone_id" data-zone-filter-target>
                    <option value=""><?= e(t('common.options.no_zone')) ?></option>
                    <?php foreach ($zoneOptions as $z): ?>
                        <option value="<?= (int) $z['id'] ?>" data-building="<?= (int) $z['building_id'] ?>" <?= $selectedZone === (string) $z['id'] ? 'selected' : '' ?>>
                            <?= e($z['code'] . ' — ' . $z['name'] . ($z['status'] === 'ngung' ? t('lit.inactive') : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint"><?= t('ui.optional_if_chosen_the_zone_must') ?></div>
                <?= field_error($form, 'zone_id') ?>
            </div>
            <div class="form-group <?= has_error($form, 'code') ? 'has-error' : '' ?>">
                <label for="code"><?= t('common.fields.apartment_code') ?> <span class="required">*</span></label>
                <input type="text" id="code" name="code" maxlength="30" required value="<?= e(form_value($form, $apartment, 'code')) ?>">
                <div class="form-hint"><?= t('ui.e_g_lk2_s01_bt4_2305') ?></div>
                <?= field_error($form, 'code') ?>
            </div>
            <div class="form-group <?= has_error($form, 'area') ? 'has-error' : '' ?>">
                <label for="area"><?= t('common.fields.area') ?> <span class="required">*</span></label>
                <input type="text" inputmode="decimal" id="area" name="area" required value="<?= e(form_value($form, $apartment, 'area')) ?>">
                <div class="form-hint"><?= t('ui.a_positive_number_with_up_to') ?></div>
                <?= field_error($form, 'area') ?>
            </div>
            <div class="form-group <?= has_error($form, 'occupancy_status') ? 'has-error' : '' ?>">
                <label for="occupancy_status"><?= t('common.fields.occupancy') ?> <span class="required">*</span></label>
                <select id="occupancy_status" name="occupancy_status" required>
                    <?php foreach (master_labels('occupancy_status') as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedOccupancy === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint"><?= t('ui.determines_the_service_fee_unit_price') ?></div>
                <?= field_error($form, 'occupancy_status') ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? t('lit.save_changes') : t('lit.add_apartment') ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url($isEdit ? '/apartments/' . $apartment['id'] : '/apartments')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
