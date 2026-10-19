<?php
/** @var array $filters @var array $pager @var array $periodOptions @var array $zoneOptions */
$breadcrumbs = [[t('crumbs.readings'), null], [t('page.readings'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('landing.readings.heading')) ?></h1>
        <p class="page-subtitle"><?= e(t('landing.readings.subtitle')) ?></p>
    </div>
</div>
<div class="panel">
    <form method="get" action="<?= e(base_url('/readings')) ?>" class="toolbar">
        <div class="form-group">
            <label for="period_id"><?= e(t('common.fields.period')) ?></label>
            <select id="period_id" name="period_id">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach ($periodOptions as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $filters['period_id'] === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['period_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="zone_id">Khu</label>
            <select id="zone_id" name="zone_id">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach ($zoneOptions as $z): ?>
                    <option value="<?= (int) $z['id'] ?>" <?= $filters['zone_id'] === (string) $z['id'] ? 'selected' : '' ?>><?= e($z['building_code'] . ' / ' . $z['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label for="q"><?= e(t('common.fields.apartment')) ?></label><input type="text" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('common.placeholders.apartment_code')) ?>"></div>
        <div class="form-group"><label for="meter_code"><?= e(t('common.fields.meter_code')) ?></label><input type="text" id="meter_code" name="meter_code" value="<?= e($filters['meter_code']) ?>"></div>
        <div class="form-group">
            <label for="utility_type"><?= e(t('common.fields.utility')) ?></label>
            <select id="utility_type" name="utility_type"><option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('utility_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['utility_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="status"><?= e(t('common.fields.status')) ?></label>
            <select id="status" name="status"><option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (config_labels('readings.reading_status') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="source"><?= e(t('common.fields.source')) ?></label>
            <select id="source" name="source"><option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (config_labels('readings.reading_source') as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['source'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/readings')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>

    <?php if ($pager['data'] === []): ?>
        <div class="empty-state"><div class="empty-state__icon">🔎</div><p><?= e(t('landing.readings.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.period')) ?></th><th><?= e(t('common.fields.apartment')) ?></th><th><?= e(t('common.fields.meter')) ?></th><th><?= e(t('common.fields.utility')) ?></th><th class="num"><?= e(t('common.fields.prev_reading')) ?></th><th class="num"><?= e(t('common.fields.curr_reading')) ?></th><th class="num"><?= e(t('common.fields.consumption')) ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= e(t('common.fields.source')) ?></th><th><?= e(t('common.fields.entered_by')) ?></th><th><?= e(t('common.fields.time')) ?></th><th class="actions"></th></tr></thead>
                <tbody>
                <?php foreach ($pager['data'] as $r): ?>
                    <tr>
                        <td><?= e($r['period_code']) ?></td>
                        <td><?= e($r['building_code'] . ' / ' . $r['apartment_code']) ?></td>
                        <td><?= e($r['meter_code']) ?></td>
                        <td><?= e(label_of('utility_type', $r['utility_type'])) ?></td>
                        <td class="num"><?= e(format_decimal($r['previous_reading'])) ?></td>
                        <td class="num"><?= e(format_decimal($r['current_reading'])) ?></td>
                        <td class="num"><strong><?= e(format_decimal($r['consumption'])) ?></strong></td>
                        <td><?= reading_badge($r['status']) ?></td>
                        <td><?= e(config_labels('readings.reading_source')[$r['source']] ?? $r['source']) ?><?= $r['import_batch_id'] ? ' <a href="' . e(base_url('/imports/' . $r['import_batch_id'])) . '">#' . (int) $r['import_batch_id'] . '</a>' : '' ?></td>
                        <td><?= e($r['entered_by_name'] ?? '—') ?></td>
                        <td><?= e($r['created_at']) ?></td>
                        <td class="actions"><a href="<?= e(base_url('/readings/' . $r['id'])) ?>"><?= e(t('common.actions.details')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php $basePath = '/readings'; require BASE_PATH . '/resources/views/partials/pagination.php'; ?>
</div>
