<?php
/** @var array $reading @var array $form */
$breadcrumbs = [[t('menu.groups.readings'), null], [t('menu.readings'), '/readings'], ['#' . $reading['id'], '/readings/' . $reading['id']], [t('common.actions.edit'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$unit = config('pricing.utility_unit')[$reading['utility_type']] ?? '';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.edit_reading_2') ?><?= (int) $reading['id'] ?> — <?= e($reading['meter_code']) ?><?= t('ui.period_2') ?> <?= e($reading['period_code']) ?></h1>
        <p class="page-subtitle"><?= t('ui.fn_04_07_edit_before_the') ?></p>
    </div>
</div>
<?php if ($reading['status'] === 'da_xac_nhan'): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= t('ui.this_reading_was_confirmed_by_bql') ?> <strong><?= t('ui.draft') ?></strong> <?= t('ui.and_needs_re_approval_br_41') ?></div>
<?php endif; ?>
<div class="panel form-card form-card--wide">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url('/readings/' . $reading['id'] . '/update')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="version_token" value="<?= e($reading['version_token']) ?>">
        <div class="form-grid">
            <div class="form-group"><label><?= t('ui.previous_reading_read_only') ?></label><input type="text" value="<?= e(format_input($reading['previous_reading'])) ?>" readonly></div>
            <div class="form-group <?= has_error($form, 'current_reading') ? 'has-error' : '' ?>">
                <label for="current_reading"><?= t('alert_j16.new_csc') ?> <span class="required">*</span></label>
                <input type="text" inputmode="decimal" id="current_reading" name="current_reading" value="<?= e(form_value($form, null, 'current_reading', format_input($reading['current_reading']))) ?>"
                       data-current-reading data-previous="<?= e($reading['previous_reading']) ?>">
                <?= field_error($form, 'current_reading') ?>
            </div>
            <div class="form-group"><label><?= t('ui.consumption_preview') ?> <?= e($unit) ?>)</label><input type="text" readonly data-consumption-preview></div>
        </div>
        <div class="form-group <?= has_error($form, 'note') ? 'has-error' : '' ?>">
            <label for="note"><?= t('alert_j16.adjust_reason') ?> <span class="required">*</span></label>
            <textarea id="note" name="note" rows="2" maxlength="500"><?= e(form_value($form, null, 'note')) ?></textarea>
            <div class="form-hint"><?= t('ui.val_04_15_required_stored_in') ?></div>
            <?= field_error($form, 'note') ?>
        </div>
        <?php if (has_error($form, 'meter_reset')): ?>
            <div class="notice-box" style="margin-bottom:16px"><?= t('ui.current_reading_lower_than_the_previous') ?><?= t('ui.the_system_does_not_save_current') ?></div>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= t('ui.save_adjustment') ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url('/readings/' . $reading['id'])) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
