<?php
/** @var array $reading (kèm history) */
$breadcrumbs = [[t('menu.groups.readings'), null], [t('menu.readings'), '/readings'], ['#' . $reading['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$unit = config('pricing.utility_unit')[$reading['utility_type']] ?? '';
$locked = $reading['period_status'] === 'da_khoa';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.reading') ?><?= (int) $reading['id'] ?> — <?= e($reading['meter_code']) ?><?= t('ui.period_2') ?> <?= e($reading['period_code']) ?></h1>
        <p class="page-subtitle"><?= reading_badge($reading['status']) ?> <?= e(config_labels('readings.reading_source')[$reading['source']] ?? $reading['source']) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (!$locked && can('meter_readings.update')): ?>
            <a class="action-edit btn btn--ghost" href="<?= e(base_url('/readings/' . $reading['id'] . '/edit')) ?>"><?= t('ui.edit_reading') ?></a>
        <?php endif; ?>
        <?php if (!$locked && $reading['status'] === 'nhap' && can('meter_readings.approve')): ?>
            <form method="post" action="<?= e(base_url('/periods/' . $reading['billing_period_id'] . '/approvals')) ?>" class="inline-form js-confirm"
                  data-confirm-title="<?= e(t('lit.approve_reading_title')) ?>" data-confirm-message="<?= e(t('lit.approve_reading_message', ['value' => format_decimal($reading['current_reading']), 'cl' => format_decimal($reading['consumption']), 'unit' => $unit])) ?>" data-confirm-button="<?= t('common.actions.approve') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="reading_ids[]" value="<?= (int) $reading['id'] ?>">
                <input type="hidden" name="token_<?= (int) $reading['id'] ?>" value="<?= e($reading['version_token']) ?>">
                <input type="hidden" name="back" value="reading">
                <button type="submit" class="btn btn--primary"><?= e(t('common.actions.approve')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('menu.apartments') ?></dt><dd><?= e($reading['building_code'] . ' / ' . $reading['apartment_code']) ?><?= $reading['zone_code'] ? ' · khu ' . e($reading['zone_code']) : '' ?></dd></div>
        <div><dt><?= t('menu.meters') ?></dt><dd><?= e($reading['meter_code']) ?> — <?= e(label_of('utility_type', $reading['utility_type'])) ?>, <?= e(label_of('usage_type', $reading['usage_type'])) ?></dd></div>
        <div><dt><?= t('common.fields.prev_reading') ?></dt><dd><?= e(format_decimal($reading['previous_reading'])) ?> <?= e($unit) ?></dd></div>
        <div><dt>CSC</dt><dd><?= e(format_decimal($reading['current_reading'])) ?> <?= e($unit) ?></dd></div>
        <div><dt><?= t('ui.consumption_current_previous') ?></dt><dd class="price-big"><?= e(format_decimal($reading['consumption'])) ?> <?= e($unit) ?></dd></div>
        <div><dt><?= t('ui.entered_by_at') ?></dt><dd><?= e(($reading['entered_by_name'] ?? '—') . ' · ' . $reading['created_at']) ?></dd></div>
        <div><dt><?= t('ui.approved_by_at') ?></dt><dd><?= e(($reading['confirmed_by_name'] ?? '—') . ($reading['confirmed_at'] ? ' · ' . $reading['confirmed_at'] : '')) ?></dd></div>
        <div><dt><?= t('period_show.imports') ?></dt><dd><?php if ($reading['import_batch_id']): ?><a href="<?= e(base_url('/imports/' . $reading['import_batch_id'])) ?>">#<?= (int) $reading['import_batch_id'] ?> — <?= e($reading['import_file_name']) ?></a><?php else: ?><?= t('ui.manual_entry') ?><?php endif; ?></dd></div>
        <div style="grid-column: 1 / -1"><dt><?= t('alert_show.adjust_note') ?></dt><dd><?= e($reading['note'] ?? '—') ?></dd></div>
    </dl>
</div>

<?php if (can('meter_readings.approve') && $reading['status'] === 'nhap'): ?>
    <p class="form-hint"><?= t('ui.return_to_technical_for_correction') ?> <?= t('ui.the_schema_has_no_needs_correction') ?></p>
<?php endif; ?>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= t('ui.change_history_audit') ?></h2></div>
    <?php if ($reading['history'] === []): ?>
        <div class="empty-state"><p><?= t('ui.no_audit_records_for_this_reading') ?><?= $reading['import_batch_id'] ? t('lit.imported_readings_are_audited_per_batch') : '' ?>.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.time')) ?></th><th><?= e(t('common.fields.person')) ?></th><th><?= e(t('common.fields.action')) ?></th><th><?= e(t('common.fields.old_value')) ?></th><th><?= e(t('common.fields.new_value')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($reading['history'] as $h): ?>
                    <tr>
                        <td><?= e($h['created_at']) ?></td>
                        <td><?= e($h['user_name'] ?? t('crumbs.system')) ?></td>
                        <td><?= e($h['action']) ?></td>
                        <td><code class="json"><?= e((string) $h['old_value']) ?></code></td>
                        <td><code class="json"><?= e((string) $h['new_value']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
