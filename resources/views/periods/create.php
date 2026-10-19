<?php
/** @var array $suggestion @var array $form */
$breadcrumbs = [[t('menu.groups.readings'), null], [t('menu.periods'), '/periods'], [t('common.actions.create_period'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$month = form_value($form, null, 'month', (string) $suggestion['month']);
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.create_reading_period') ?></h1>
        <p class="page-subtitle"><?= t('ui.period_code_format_mm_yyyy_meters') ?></p>
    </div>
</div>
<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url('/periods')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'month') ? 'has-error' : '' ?>">
                <label for="month"><?= t('ui.month') ?> <span class="required">*</span></label>
                <select id="month" name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $month === (string) $m ? 'selected' : '' ?>><?= t('ui.month') ?> <?= $m ?></option>
                    <?php endfor; ?>
                </select>
                <?= field_error($form, 'month') ?>
            </div>
            <div class="form-group <?= has_error($form, 'year') ? 'has-error' : '' ?>">
                <label for="year"><?= t('common.fields.year') ?> <span class="required">*</span></label>
                <input type="text" inputmode="numeric" id="year" name="year" value="<?= e(form_value($form, null, 'year', (string) $suggestion['year'])) ?>">
                <?= field_error($form, 'year') ?>
            </div>
            <div class="form-group <?= has_error($form, 'start_date') ? 'has-error' : '' ?>">
                <label for="start_date"><?= t('ui.start_date') ?></label>
                <input type="date" id="start_date" name="start_date" value="<?= e(form_value($form, null, 'start_date')) ?>">
                <div class="form-hint"><?= t('ui.leave_empty_first_day_of_the') ?></div>
                <?= field_error($form, 'start_date') ?>
            </div>
            <div class="form-group <?= has_error($form, 'end_date') ? 'has-error' : '' ?>">
                <label for="end_date"><?= t('ui.end_date') ?></label>
                <input type="date" id="end_date" name="end_date" value="<?= e(form_value($form, null, 'end_date')) ?>">
                <div class="form-hint"><?= t('ui.leave_empty_last_day_of_the') ?></div>
                <?= field_error($form, 'end_date') ?>
            </div>
            <div class="form-group <?= has_error($form, 'reading_due_date') ? 'has-error' : '' ?>">
                <label for="reading_due_date"><?= t('ui.reading_entry_deadline') ?></label>
                <input type="date" id="reading_due_date" name="reading_due_date" value="<?= e(form_value($form, null, 'reading_due_date')) ?>">
                <div class="form-hint"><?= t('ui.optional_dx_used_for_the_processing') ?></div>
                <?= field_error($form, 'reading_due_date') ?>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= e(t('common.actions.create_period')) ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url('/periods')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
