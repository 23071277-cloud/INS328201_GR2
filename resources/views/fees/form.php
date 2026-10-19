<?php
/** @var ?array $fee @var string $presetFeeType @var array $form */
$isEdit = $fee !== null;
$feeTypes = config_labels('pricing.fee_type');
$selectedType = $isEdit ? $fee['fee_type'] : form_value($form, null, 'fee_type', $presetFeeType);
$breadcrumbs = [[t('menu.groups.pricing'), null], [t('menu.fees'), '/fees'], [$isEdit ? t('lit.edit_unit_price') : t('lit.add_unit_price'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$action = $isEdit ? '/fees/' . $fee['id'] . '/update' : '/fees';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= $isEdit ? t('lit.edit_unit_price_not_yet_in') : t('lit.add_fee_unit_price') ?></h1>
        <p class="page-subtitle"><?= $isEdit ? t('lit.only_upcoming_unit_prices_can_be') : t('lit.saving_creates_a_new_unit_price') ?></p>
    </div>
</div>

<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($action)) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="version_token" value="<?= e($fee['version_token']) ?>">
        <?php endif; ?>
        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'fee_type') ? 'has-error' : '' ?>">
                <label for="fee_type"><?= t('common.fields.fee_type') ?> <span class="required">*</span></label>
                <select id="fee_type" name="fee_type" <?= $isEdit ? 'disabled' : '' ?>>
                    <option value=""><?= e(t('common.options.select_fee_type')) ?></option>
                    <?php foreach ($feeTypes as $value => $meta): ?>
                        <option value="<?= e($value) ?>" <?= $selectedType === $value ? 'selected' : '' ?>><?= e($meta['label'] . ' (' . message_t($meta['unit']) . ')') ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($form, 'fee_type') ?>
            </div>
            <div class="form-group <?= has_error($form, 'unit_price') ? 'has-error' : '' ?>">
                <label for="unit_price"><?= t('ui.unit_price_vnd') ?> <span class="required">*</span></label>
                <input type="text" inputmode="decimal" id="unit_price" name="unit_price" value="<?= e(format_input(form_value($form, $fee, 'unit_price'))) ?>">
                <div class="form-hint"><?= t('ui.must_be_0_val_03_01') ?></div>
                <?= field_error($form, 'unit_price') ?>
            </div>
            <div class="form-group <?= has_error($form, 'effective_date') ? 'has-error' : '' ?>">
                <label for="effective_date"><?= t('ui.effective_date') ?> <span class="required">*</span></label>
                <input type="date" id="effective_date" name="effective_date" value="<?= e(form_value($form, $fee, 'effective_date')) ?>">
                <div class="form-hint"><?= t('ui.must_be_after_the_latest_locked') ?></div>
                <?= field_error($form, 'effective_date') ?>
            </div>
        </div>
        <?php if (in_array($selectedType, ['bao_hiem_chay_no', 'mo_lai_dich_vu'], true)): ?>
            <p class="form-hint"><?= e(message_t($feeTypes[$selectedType]['rule'])) ?></p>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? t('lit.save_changes') : t('lit.add_unit_price') ?></button>
            <a class="btn btn--secondary" href="<?= e(url_with('/fees', ['fee_type' => $selectedType])) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
