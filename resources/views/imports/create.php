<?php
/** @var array $period @var bool $strict @var array $form */
$breadcrumbs = [[t('menu.groups.readings'), null], [t('menu.periods'), '/periods'], [$period['period_code'], '/periods/' . $period['id']], [t('ui.import_excel'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$headers = config('readings.import.headers');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.import_readings_from_excel_period') ?> <?= e($period['period_code']) ?></h1>
        <p class="page-subtitle"><?= t('ui.upload_check_flow') ?> <strong><?= t('ui.preview') ?></strong> <?= t('ui.saved_only_after_confirmation_nothing_is') ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(base_url('/periods/' . $period['id'] . '/imports/template')) ?>"><?= t('ui.download_period_template') ?></a>
    </div>
</div>

<?php if ($period['status'] === 'da_khoa'): ?>
    <div class="notice-box"><?= t('worklist.locked') ?></div>
<?php else: ?>
<div class="panel form-card form-card--wide">
    <?php if (has_error($form, 'file')): ?><div class="form-summary"><?= e($form['errors']['file'][0]) ?></div><?php endif; ?>
    <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/imports')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <div class="form-group <?= has_error($form, 'file') ? 'has-error' : '' ?>">
            <label for="file">File Excel (.xlsx) <span class="required">*</span></label>
            <input type="file" id="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
            <div class="form-hint"><?= t('ui.only_xlsx_excel_2007_is_accepted') ?> <?= (int) (config('readings.import.max_bytes') / 1024 / 1024) ?> MB, <?= (int) config('readings.import.max_rows') ?> <?= t('ui.rows') ?></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= t('ui.upload_check') ?></button></div>
    </form>
</div>

<div class="panel">
    <h2 class="panel__title"><?= t('ui.file_rules') ?></h2>
    <ul class="rule-list">
        <li><?= t('ui.header_row') ?> <?= implode(' · ', array_map(static fn ($h): string => '<code>' . e($h) . '</code>', $headers)) ?> <?= t('ui.in_this_order_later_columns_are') ?> <code><?= t('ui.grand_total') ?></code> <?= t('ui.which_is_used_for_reconciliation') ?></li>
        <li><code>CH</code> <?= t('ui.apartment_code_if_the_apartment_has') ?> <code>S01 KD</code> <?= t('ui.label_the_meter_s_display_label') ?></li>
        <li><code><?= t('common.fields.prev_reading') ?></code> <?= t('ui.must_match_the_system_s_previous') ?> <strong><?= t('ui.calculates_consumption_itself') ?></strong><?= t('ui.consumption_amount_columns_in_the_file') ?></li>
        <li><?= t('ui.a_row_with') ?> <code>STT</code> <?= t('ui.or') ?> <code>CH</code> <?= t('ui.control_total_row_hint') ?> <strong><?= t('ui.warning') ?></strong><?= t('ui.it_does_not_block_val_04') ?></li>
        <li><?= $strict
                ? t('lit.current_mode_strong_all_or_nothing')
                : t('lit.current_mode_strong_partial_save_strong') ?></li>
        <li><?= t('ui.if_a_row_has_an_error') ?> <strong><?= t('ui.the_whole_row') ?></strong> <?= t('ui.is_not_saved') ?></li>
    </ul>
</div>
<?php endif; ?>
