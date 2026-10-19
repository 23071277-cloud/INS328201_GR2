<?php
/** @var array $base phiên bản gốc (mới nhất của chuỗi) @var array $form */
$unitLabel = config('pricing.utility_unit')[$base['utility_type']] ?? '';
$isBusiness = $base['usage_type'] === 'kinh_doanh';
$tierRows = $form['old']['tiers'] ?? $base['tiers'];
$tierRows = array_values(array_filter((array) $tierRows, static fn ($r): bool => is_array($r)
    && (($r['tier_from'] ?? '') !== '' || ($r['tier_to'] ?? '') !== '' || ($r['unit_price'] ?? '') !== '')));
$breadcrumbs = [[t('menu.groups.pricing'), null], [t('menu.tariff_proposals'), '/tariff-proposals'], [t('lit.create_proposal'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.update_proposal') ?> <?= e(label_of('utility_type', $base['utility_type']) . ' · ' . label_of('usage_type', $base['usage_type'])) ?></h1>
        <p class="page-subtitle"><?= t('ui.based_on_version_v') ?><?= (int) $base['version_no'] ?> (#<?= (int) $base['id'] ?><?= t('ui.effective') ?> <?= e(format_date($base['effective_date'])) ?><?= t('ui.submitting_a_proposal_does') ?> <strong><?= t('ui.not') ?></strong> <?= t('ui.change_the_current_tariff_it_waits') ?></p>
    </div>
</div>

<div class="panel form-card form-card--wide">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url('/tariff-proposals')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="tariff_id" value="<?= (int) $base['id'] ?>">
        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'effective_date') ? 'has-error' : '' ?>">
                <label for="effective_date"><?= t('ui.proposed_effective_date') ?> <span class="required">*</span></label>
                <input type="date" id="effective_date" name="effective_date" value="<?= e(form_value($form, null, 'effective_date')) ?>">
                <div class="form-hint"><?= t('ui.must_be_after_the_latest_locked') ?></div>
                <?= field_error($form, 'effective_date') ?>
            </div>
            <div class="form-group <?= has_error($form, 'vat_percent') ? 'has-error' : '' ?>">
                <label for="vat_percent"><?= e(t('common.fields.vat')) ?></label>
                <input type="text" inputmode="decimal" id="vat_percent" name="vat_percent" value="<?= e(format_input(form_value($form, $base, 'vat_percent'))) ?>">
                <div class="form-hint"><?= t('ui.leave_empty_vat_not_itemised') ?></div>
                <?= field_error($form, 'vat_percent') ?>
            </div>
            <?php if ($isBusiness): ?>
                <div class="form-group <?= has_error($form, 'business_unit_price') ? 'has-error' : '' ?>">
                    <label for="business_unit_price"><?= t('ui.new_business_unit_price_vnd') ?><?= e($unitLabel) ?>) <span class="required">*</span></label>
                    <input type="text" inputmode="decimal" id="business_unit_price" name="business_unit_price" value="<?= e(format_input(form_value($form, $base, 'business_unit_price'))) ?>">
                    <?= field_error($form, 'business_unit_price') ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!$isBusiness): ?>
            <h3 class="section-title"><?= t('ui.proposed_tiers') ?></h3>
            <?php require BASE_PATH . '/resources/views/partials/tier_editor.php'; ?>
        <?php endif; ?>
        <div class="form-group <?= has_error($form, 'reason') ? 'has-error' : '' ?>">
            <label for="reason"><?= t('ui.reason_basis_for_the_change') ?></label>
            <textarea id="reason" name="reason" rows="3" maxlength="500" placeholder="<?= t('ui.e_g_new_price_notice_from') ?>"><?= e(form_value($form, null, 'reason')) ?></textarea>
            <?= field_error($form, 'reason') ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= t('ui.submit_proposal') ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url('/tariff-proposals')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
