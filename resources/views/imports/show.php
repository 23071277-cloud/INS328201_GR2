<?php
/** @var array $batch (kèm control, errors, analysis, saved_ids) @var bool $strict */
$breadcrumbs = [[t('crumbs.readings'), null], [t('page.periods'), '/periods'], [$batch['period_code'], '/periods/' . $batch['billing_period_id']], [t('import_show.batch', ['id' => $batch['id']]), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$pendingConfirm = $batch['status'] === 'da_kiem_tra';
$analysis = $batch['analysis'];
$control = $pendingConfirm && $analysis !== null ? $analysis['control'] : ['status' => $batch['control'] === null ? 'missing' : ((float) $batch['control_total_diff'] === 0.0 ? 'match' : 'mismatch'), 'details' => $batch['control']];
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('import_show.batch', ['id' => (int) $batch['id']])) ?> — <?= e($batch['file_name']) ?></h1>
        <p class="page-subtitle"><?= import_badge($batch['status']) ?> <?= t('import_show.subtitle_html', ['code' => e($batch['period_code']), 'user' => e($batch['uploaded_by_name'] ?? '—'), 'at' => e($batch['created_at'])]) ?></p>
    </div>
</div>

<?php if (isset($batch['analysis_error'])): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= e(message_t($batch['analysis_error'])) ?></div>
<?php endif; ?>

<div class="card-grid">
    <?php $counts = $pendingConfirm && $analysis !== null ? $analysis : $batch; ?>
    <div class="stat-card"><div class="stat-card__label"><?= e(t('import_show.total_rows')) ?></div><div class="stat-card__value"><?= (int) $counts['total_rows'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= e(t('import_show.valid_rows')) ?></div><div class="stat-card__value"><?= (int) $counts['valid_rows'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= e(t('import_show.error_rows')) ?></div><div class="stat-card__value"><?= (int) $counts['error_rows'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= e($pendingConfirm ? t('import_show.will_save') : t('period_show.saved_readings')) ?></div><div class="stat-card__value"><?= $pendingConfirm && $analysis !== null ? array_sum(array_map(static fn ($r) => count($r['readings']), $analysis['rows'])) : count($batch['saved_ids']) ?></div></div>
</div>

<div class="panel">
    <div class="panel__head">
        <h2 class="panel__title"><?= e(t('import_show.control')) ?></h2>
        <?php if ($control['status'] === 'match'): ?><span class="badge badge--active">MATCH</span>
        <?php elseif ($control['status'] === 'mismatch'): ?><span class="badge badge--danger">MISMATCH</span>
        <?php else: ?><span class="badge badge--muted"><?= e(t('import_show.no_total_row')) ?></span><?php endif; ?>
    </div>
    <?php if (empty($control['details'])): ?>
        <p class="form-hint"><?= e(t('import_show.no_total_hint')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('import_show.metric')) ?></th><th class="num"><?= e(t('import_show.expected')) ?></th><th class="num"><?= e(t('import_show.actual')) ?></th><th class="num">DIFFERENCE</th><th><?= e(t('common.fields.result')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($control['details'] as $key => $metric): ?>
                    <tr class="<?= (float) $metric['difference'] != 0.0 ? 'row-changed' : '' ?>">
                        <td><?= e(label_t('import.metric.' . $key, (string) $metric['label'])) ?> <small class="form-hint">(<?= e($key) ?>)</small></td>
                        <td class="num"><?= e(format_decimal($metric['expected'])) ?></td>
                        <td class="num"><?= e(format_decimal($metric['actual'])) ?></td>
                        <td class="num"><?= e(((float) $metric['difference'] > 0 ? '+' : '') . format_decimal($metric['difference'])) ?></td>
                        <td><?= (float) $metric['difference'] == 0.0 ? '<span class="badge badge--active">MATCH</span>' : '<span class="badge badge--danger">MISMATCH</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($control['status'] === 'mismatch'): ?><p class="form-hint"><?= e(t('import_show.mismatch_hint')) ?></p><?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($pendingConfirm && $analysis !== null): ?>
    <div class="panel">
        <div class="panel__head"><h2 class="panel__title"><?= e(t('import_show.preview')) ?></h2></div>
        <?php foreach ($analysis['warnings'] as $w): ?><div class="alert alert--info"><?= e(message_t($w)) ?></div><?php endforeach; ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.row')) ?></th><th>CH</th><th><?= e(t('common.fields.apartment')) ?></th><th><?= e(t('common.fields.meter')) ?></th><th class="num"><?= e(t('common.fields.prev_reading')) ?></th><th class="num"><?= e(t('common.fields.curr_reading')) ?></th><th class="num"><?= e(t('import_show.cl_calc')) ?></th><th><?= e(t('common.fields.result')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($analysis['rows'] as $row): ?>
                    <?php $span = max(1, count($row['readings'])); ?>
                    <?php if ($row['readings'] === []): ?>
                        <tr class="row-error">
                            <td><?= (int) $row['row_no'] ?></td><td><?= e($row['ch']) ?></td><td><?= e($row['apartment'] ?? '—') ?></td>
                            <td colspan="4">—</td>
                            <td><span class="badge badge--danger"><?= e(t('import_show.error')) ?></span><?php foreach ($row['errors'] as $err): ?><div class="form-error"><?= e(message_t($err)) ?></div><?php endforeach; ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($row['readings'] as $i => $rd): ?>
                            <tr>
                                <?php if ($i === 0): ?><td rowspan="<?= $span ?>"><?= (int) $row['row_no'] ?></td><td rowspan="<?= $span ?>"><?= e($row['ch']) ?></td><td rowspan="<?= $span ?>"><?= e($row['apartment']) ?></td><?php endif; ?>
                                <td><?= e($rd['meter_code']) ?> <small>(<?= e(label_of('utility_type', $rd['utility'])) ?>)</small></td>
                                <td class="num"><?= e(format_decimal($rd['previous'])) ?></td>
                                <td class="num"><?= e(format_decimal($rd['current'])) ?></td>
                                <td class="num"><strong><?= e(format_decimal($rd['consumption'])) ?></strong></td>
                                <?php if ($i === 0): ?><td rowspan="<?= $span ?>"><span class="badge badge--active"><?= e(t('import_show.valid')) ?></span><?php foreach ($row['warnings'] as $w): ?><div class="form-hint">⚠ <?= e(message_t($w)) ?></div><?php endforeach; ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (can('meter_readings.import')): ?>
        <?php $blockedByStrict = $strict && $analysis['error_rows'] > 0; ?>
        <div class="panel">
            <h2 class="panel__title"><?= e(t('import_show.confirm_save')) ?></h2>
            <p class="form-hint">
                <?= e($strict ? t('import_show.strict') : t('import_show.partial', ['id' => (int) $batch['id']])) ?>
                <?= t('import_show.recheck_html') ?>
            </p>
            <form method="post" action="<?= e(base_url('/imports/' . $batch['id'] . '/confirm')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <?php if ($analysis['control']['status'] === 'mismatch'): ?>
                    <label class="checkbox-line"><input type="checkbox" name="acknowledge_mismatch" value="1"> <?= e(t('import_show.ack_mismatch')) ?></label>
                <?php endif; ?>
                <button type="submit" class="btn btn--primary" <?= $blockedByStrict || $analysis['valid_rows'] === 0 ? 'disabled' : '' ?>><?= e(t('import_show.save_valid', ['n' => (int) $analysis['valid_rows']])) ?></button>
            </form>
            <form method="post" action="<?= e(base_url('/imports/' . $batch['id'] . '/cancel')) ?>" class="inline-form js-confirm" data-confirm-title="<?= e(t('import_show.cancel_title')) ?>" data-confirm-message="<?= e(t('import_show.cancel_message', ['id' => (int) $batch['id']])) ?>" data-confirm-button="<?= e(t('import_show.cancel_batch')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--secondary"><?= e(t('import_show.cancel_batch')) ?></button>
            </form>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="panel">
        <div class="panel__head"><h2 class="panel__title"><?= e(t('import_show.result')) ?></h2>
            <?php if ($batch['saved_ids'] !== []): ?><a class="btn btn--ghost btn--sm" href="<?= e(url_with('/readings', ['period_id' => $batch['billing_period_id'], 'source' => 'import'])) ?>"><?= e(t('import_show.view_saved')) ?></a><?php endif; ?>
        </div>
        <p><?= e(t('import_show.saved_from_batch')) ?> <strong><?= count($batch['saved_ids']) ?></strong><?= $batch['saved_ids'] !== [] ? ' (#' . implode(', #', array_map('intval', $batch['saved_ids'])) . ')' : '' ?>.</p>
        <?php if ($batch['errors'] !== []): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th><?= e(t('common.fields.row')) ?></th><th>CH</th><th><?= e(t('common.fields.error')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($batch['errors'] as $err): ?>
                        <tr><td><?= (int) $err['row_no'] ?></td><td><?= e($err['apartment_code'] ?? '—') ?></td><td><?= e(message_t($err['error_message'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
