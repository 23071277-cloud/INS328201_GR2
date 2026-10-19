<?php
/** @var ?array $tariff null = tạo mới @var ?array $source phiên bản dùng để điền sẵn (?from=) @var array $form */
$isEdit = $tariff !== null;
$prefill = $tariff ?? $source;
$utility = $isEdit ? $tariff['utility_type'] : form_value($form, $source, 'utility_type', 'dien');
$usage = $isEdit ? $tariff['usage_type'] : form_value($form, $source, 'usage_type', 'sinh_hoat');

$tierRows = $form['old']['tiers'] ?? ($prefill['tiers'] ?? []);
$tierRows = array_values(array_filter((array) $tierRows, static fn ($r): bool => is_array($r)
    && (($r['tier_from'] ?? '') !== '' || ($r['tier_to'] ?? '') !== '' || ($r['unit_price'] ?? '') !== '')));
$unitLabel = config('pricing.utility_unit')[$utility] ?? '';

$breadcrumbs = [[t('menu.groups.pricing'), null], [t('menu.tariffs'), '/tariffs']];
if ($isEdit) {
    $breadcrumbs[] = ['#' . $tariff['id'], '/tariffs/' . $tariff['id']];
}
$breadcrumbs[] = [$isEdit ? t('common.actions.edit') : t('lit.add_version'), null];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$action = $isEdit ? '/tariffs/' . $tariff['id'] . '/update' : '/tariffs' . ($source !== null ? '?from=' . (int) $source['id'] : '');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= $isEdit ? t('lit.edit_tariff_version_not_yet_in') : t('lit.add_tariff_version') ?></h1>
        <p class="page-subtitle">
            <?php if ($isEdit): ?>
                <?= t('ui.only_versions_that_are') ?> <strong><?= t('ui.upcoming') ?></strong><?= t('ui.can_be_edited_versions_already_or') ?>
            <?php elseif ($source !== null): ?>
                <?= t('ui.prefilled_from_version') ?><?= (int) $source['id'] ?><?= t('ui.saving_creates_a') ?> <strong><?= t('ui.new_version') ?></strong><?= t('ui.version_2') ?><?= (int) $source['id'] ?> <?= t('ui.is_unchanged') ?>
            <?php else: ?>
                <?= t('ui.saving_creates_a_new_version_of') ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="panel form-card form-card--wide">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($action)) ?>" novalidate data-usage-form>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="version_token" value="<?= e($tariff['version_token']) ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'utility_type') ? 'has-error' : '' ?>">
                <label for="utility_type"><?= t('common.fields.utility_type') ?> <span class="required">*</span></label>
                <select id="utility_type" name="utility_type" data-utility-select <?= $isEdit ? 'disabled' : '' ?>>
                    <?php foreach (master_labels('utility_type') as $value => $label): ?>
                        <option value="<?= e($value) ?>" data-unit="<?= e(config('pricing.utility_unit')[$value] ?? '') ?>" <?= $utility === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($form, 'utility_type') ?>
            </div>
            <div class="form-group <?= has_error($form, 'usage_type') ? 'has-error' : '' ?>">
                <label for="usage_type"><?= t('common.fields.usage_type') ?> <span class="required">*</span></label>
                <select id="usage_type" name="usage_type" data-usage-select <?= $isEdit ? 'disabled' : '' ?>>
                    <?php foreach (master_labels('usage_type') as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $usage === $value ? 'selected' : '' ?>><?= e($label . ' — ' . (config_labels('pricing.tariff_kind')[$value] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isEdit): ?><div class="form-hint"><?= t('ui.the_utility_usage_type_of_a') ?></div><?php endif; ?>
                <?= field_error($form, 'usage_type') ?>
            </div>
            <div class="form-group <?= has_error($form, 'effective_date') ? 'has-error' : '' ?>">
                <label for="effective_date"><?= t('ui.effective_date') ?> <span class="required">*</span></label>
                <input type="date" id="effective_date" name="effective_date" value="<?= e(form_value($form, $tariff, 'effective_date')) ?>">
                <div class="form-hint"><?= t('ui.must_be_after_the_latest_locked_2') ?></div>
                <?= field_error($form, 'effective_date') ?>
            </div>
            <div class="form-group <?= has_error($form, 'vat_percent') ? 'has-error' : '' ?>">
                <label for="vat_percent"><?= e(t('common.fields.vat')) ?></label>
                <input type="text" inputmode="decimal" id="vat_percent" name="vat_percent" value="<?= e(format_input(form_value($form, $prefill, 'vat_percent'))) ?>">
                <div class="form-hint"><?= t('ui.electricity_8_br_13_leave_empty') ?>.</div>
                <?= field_error($form, 'vat_percent') ?>
            </div>
        </div>

        <div data-usage-section="kinh_doanh" <?= $usage === 'kinh_doanh' ? '' : 'hidden' ?>>
            <div class="form-group <?= has_error($form, 'business_unit_price') ? 'has-error' : '' ?>" style="max-width:360px">
                <label for="business_unit_price"><?= t('ui.business_unit_price_vnd') ?><span data-unit-label><?= e($unitLabel) ?></span>) <span class="required">*</span></label>
                <input type="text" inputmode="decimal" id="business_unit_price" name="business_unit_price" value="<?= e(format_input(form_value($form, $prefill, 'business_unit_price'))) ?>">
                <div class="form-hint"><?= t('ui.flat_rate_must_be_0_val') ?></div>
                <?= field_error($form, 'business_unit_price') ?>
            </div>
        </div>

        <div data-usage-section="sinh_hoat" <?= $usage === 'sinh_hoat' ? '' : 'hidden' ?>>
            <h3 class="section-title"><?= t('ui.tiers_2') ?></h3>
            <?php require BASE_PATH . '/resources/views/partials/tier_editor.php'; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? t('lit.save_changes') : t('lit.create_version') ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url($isEdit ? '/tariffs/' . $tariff['id'] : '/tariffs')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
