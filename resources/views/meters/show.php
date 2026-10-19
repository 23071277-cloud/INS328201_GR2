<?php
/** @var array $meter (kèm reading_count) */
// Công tơ luôn thuộc một căn hộ: breadcrumb theo ngữ cảnh căn hộ (link trực tiếp bằng apartment_id, không dùng history.back)
$apartmentPath = '/apartments/' . (int) $meter['apartment_id'];
$canViewApartment = can('apartments.view');
$breadcrumbs = [[t('menu.groups.catalog'), null], [t('menu.apartments'), $canViewApartment ? '/apartments' : null],
    [$meter['apartment_code'], $canViewApartment ? $apartmentPath : null], [t('lit.meter') . $meter['meter_code'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$isActive = $meter['status'] === 'hoat_dong';
$readingCount = (int) $meter['reading_count'];
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('menu.meters') ?> <?= e($meter['meter_code']) ?></h1>
        <p class="page-subtitle"><?= status_badge($meter['status']) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($canViewApartment): ?>
            <a class="btn btn--ghost" href="<?= e(base_url($apartmentPath)) ?>"><?= t('ui.back_to_apartment') ?> <?= e($meter['apartment_code']) ?></a>
        <?php endif; ?>
        <?php if (can('meters.update')): ?>
            <a class="action-edit btn btn--ghost" href="<?= e(base_url('/meters/' . $meter['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
        <?php endif; ?>
        <?php if ($isActive && can('meters.deactivate')): ?>
            <?php
            $deactivateAction = '/meters/' . $meter['id'] . '/deactivate';
            $deactivateMessage = t('lit.deactivate_meter') . $meter['meter_code'] . t('lit.the_meter_will_no_longer_appear')
                . ($readingCount > 0 ? t('lit.readings_kept', ['n' => $readingCount]) : '');
            $deactivateLabel = t('lit.deactivate_meter_2');
            require BASE_PATH . '/resources/views/partials/deactivate_form.php';
            ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('common.fields.meter_code') ?></dt><dd><?= e($meter['meter_code']) ?></dd></div>
        <div>
            <dt><?= t('menu.apartments') ?></dt>
            <dd>
                <?php if (can('apartments.view')): ?>
                    <a href="<?= e(base_url('/apartments/' . $meter['apartment_id'])) ?>"><?= e($meter['building_code'] . ' / ' . $meter['apartment_code']) ?></a>
                <?php else: ?>
                    <?= e($meter['building_code'] . ' / ' . $meter['apartment_code']) ?>
                <?php endif; ?>
                <?= $meter['apartment_status'] === 'ngung' ? status_badge('ngung') : '' ?>
            </dd>
        </div>
        <div><dt><?= t('common.fields.utility_type') ?></dt><dd><?= e(label_of('utility_type', $meter['utility_type'])) ?></dd></div>
        <div><dt><?= t('common.fields.usage_type') ?></dt><dd><?= e(label_of('usage_type', $meter['usage_type'])) ?></dd></div>
        <div><dt><?= t('ui.display_label') ?></dt><dd><?= e($meter['display_label'] ?? '—') ?></dd></div>
        <div><dt><?= t('common.fields.initial_reading') ?></dt><dd><?= e(format_decimal($meter['initial_reading'])) ?></dd></div>
        <div><dt><?= t('ui.periods_with_readings_2') ?></dt><dd><?= $readingCount ?></dd></div>
        <div><dt><?= t('ui.last_updated') ?></dt><dd><?= e($meter['updated_at']) ?></dd></div>
    </dl>
    <?php if ($readingCount > 0): ?>
        <p class="form-hint"><?= t('ui.this_meter_has_readings_it_cannot') ?></p>
    <?php endif; ?>
</div>
