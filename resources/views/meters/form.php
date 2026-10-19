<?php
/** @var ?array $meter @var array $apartmentOptions @var string $presetApartmentId @var array $form */
$isEdit = $meter !== null;
$locked = $isEdit && (int) $meter['reading_count'] > 0; // D8: chỉ để hiển thị, Service vẫn kiểm tra
$breadcrumbs = [[t('menu.groups.catalog'), null], [t('menu.meters'), '/meters']];
if ($isEdit) {
    $breadcrumbs[] = [$meter['meter_code'], '/meters/' . $meter['id']];
}
$breadcrumbs[] = [$isEdit ? t('common.actions.edit') : t('lit.add_new'), null];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$action = $isEdit ? '/meters/' . $meter['id'] . '/update' : '/meters';
$selectedApartment = form_value($form, $meter, 'apartment_id', $presetApartmentId);
$selectedUtility = form_value($form, $meter, 'utility_type');
$selectedUsage = form_value($form, $meter, 'usage_type');
?>
<div class="page-head">
    <h1 class="page-title"><?= $isEdit ? t('lit.edit_meter') : t('lit.add_meter') ?></h1>
</div>

<div class="panel form-card">
    <?php if ($locked): ?>
        <div class="notice-box" style="margin-bottom:16px">
            <?= t('ui.this_meter_already_has') ?> <?= (int) $meter['reading_count'] ?> <?= t('ui.readings_the_apartment_utility_type_usage') ?>

        </div>
    <?php endif; ?>
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($action)) ?>" novalidate<?= $isEdit ? '' : ' data-meter-suggest data-existing-codes="' . e(json_encode(array_values($existingCodes ?? []), JSON_UNESCAPED_UNICODE)) . '"' ?>>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="updated_at" value="<?= e(form_value($form, $meter, 'updated_at')) ?>">
        <?php endif; ?>
        <?php if ($locked): ?>
            <?php foreach (['apartment_id', 'utility_type', 'usage_type'] as $lockedField): ?>
                <input type="hidden" name="<?= e($lockedField) ?>" value="<?= e((string) $meter[$lockedField]) ?>">
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'apartment_id') ? 'has-error' : '' ?>">
                <label for="apartment_id"><?= t('menu.apartments') ?> <span class="required">*</span></label>
                <select id="apartment_id" name="apartment_id" required <?= $locked ? 'disabled' : '' ?>>
                    <option value=""><?= e(t('common.options.select_apartment')) ?></option>
                    <?php foreach ($apartmentOptions as $apt): ?>
                        <option value="<?= (int) $apt['id'] ?>" data-code="<?= e($apt['code']) ?>" <?= $selectedApartment === (string) $apt['id'] ? 'selected' : '' ?>>
                            <?= e($apt['building_code'] . ' / ' . $apt['code'] . ($apt['status'] === 'ngung' ? t('lit.inactive') : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($form, 'apartment_id') ?>
            </div>
            <div class="form-group <?= has_error($form, 'meter_code') ? 'has-error' : '' ?>">
                <label for="meter_code"><?= t('common.fields.meter_code') ?> <span class="required">*</span></label>
                <input type="text" id="meter_code" name="meter_code" maxlength="30" required aria-describedby="meter_code_hint" value="<?= e(form_value($form, $meter, 'meter_code')) ?>">
                <div class="form-hint" id="meter_code_hint"><?= $isEdit ? t('lit.the_meter_s_unique_identifier_in') : t('lit.the_meter_s_unique_identifier_in_2') ?></div>
                <?= field_error($form, 'meter_code') ?>
            </div>
            <div class="form-group <?= has_error($form, 'utility_type') ? 'has-error' : '' ?>">
                <label for="utility_type"><?= t('common.fields.utility_type') ?> <span class="required">*</span></label>
                <select id="utility_type" name="utility_type" required <?= $locked ? 'disabled' : '' ?>>
                    <option value=""><?= e(t('common.options.select')) ?></option>
                    <?php foreach (master_labels('utility_type') as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedUtility === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($form, 'utility_type') ?>
            </div>
            <div class="form-group <?= has_error($form, 'usage_type') ? 'has-error' : '' ?>">
                <label for="usage_type"><?= t('ui.usage_type_tariff') ?> <span class="required">*</span></label>
                <select id="usage_type" name="usage_type" required <?= $locked ? 'disabled' : '' ?>>
                    <option value=""><?= e(t('common.options.select')) ?></option>
                    <?php foreach (master_labels('usage_type') as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedUsage === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint"><?= t('ui.residential_tiered_business_flat_rate_br') ?></div>
                <?= field_error($form, 'usage_type') ?>
            </div>
            <div class="form-group <?= has_error($form, 'display_label') ? 'has-error' : '' ?>">
                <label for="display_label"><?= t('ui.display_label') ?></label>
                <input type="text" id="display_label" name="display_label" maxlength="50" aria-describedby="display_label_hint" value="<?= e(form_value($form, $meter, 'display_label')) ?>">
                <div class="form-hint" id="display_label_hint"><?= t('ui.a_readable_name_shown_on_screen') ?><?= $isEdit ? '' : t('lit.suggested_automatically_you_can_edit_it') ?> <?= t('ui.excel_import_identifies_meters_by_the') ?></div>
                <?= field_error($form, 'display_label') ?>
            </div>
            <div class="form-group <?= has_error($form, 'initial_reading') ? 'has-error' : '' ?>">
                <label for="initial_reading"><?= t('common.fields.initial_reading') ?> <span class="required">*</span></label>
                <input type="text" inputmode="decimal" id="initial_reading" name="initial_reading" required
                       aria-describedby="initial_reading_hint" value="<?= e(form_value($form, $meter, 'initial_reading', $isEdit ? '' : '0')) ?>" <?= $locked ? 'readonly' : '' ?>>
                <div class="form-hint" id="initial_reading_hint"><?= t('ui.the_meter_reading_when_the_meter') ?></div>
                <?= field_error($form, 'initial_reading') ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? t('lit.save_changes') : t('lit.add_meter') ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url($isEdit ? '/meters/' . $meter['id'] : '/meters')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
