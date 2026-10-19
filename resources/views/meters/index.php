<?php
/** @var array $filters @var array $pager @var array $buildingOptions */
$breadcrumbs = [[t('crumbs.catalog'), null], [t('page.meters'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.meters')) ?></h1>
        <p class="page-subtitle"><?= e(t('landing.meters.subtitle')) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('meters.create')): ?>
            <a class="btn btn--primary" href="<?= e(url_with('/meters/create', ['apartment_id' => $filters['apartment_id']])) ?>"><?= e(t('common.actions.add_meter')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <form method="get" action="<?= e(base_url('/meters')) ?>" class="toolbar">
        <?php if ($filters['apartment_id'] !== ''): ?>
            <input type="hidden" name="apartment_id" value="<?= e($filters['apartment_id']) ?>">
        <?php endif; ?>
        <div class="form-group form-group--grow">
            <label for="q"><?= e(t('common.fields.search')) ?></label>
            <input type="text" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('common.placeholders.meter_or_apartment')) ?>">
        </div>
        <div class="form-group">
            <label for="building_id"><?= e(t('common.fields.building')) ?></label>
            <select id="building_id" name="building_id">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach ($buildingOptions as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= $filters['building_id'] === (string) $b['id'] ? 'selected' : '' ?>><?= e($b['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="utility_type"><?= e(t('common.fields.utility_type')) ?></label>
            <select id="utility_type" name="utility_type">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('utility_type') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['utility_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="usage_type"><?= e(t('common.fields.usage_type')) ?></label>
            <select id="usage_type" name="usage_type">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('usage_type') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['usage_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="status"><?= e(t('common.fields.status')) ?></label>
            <select id="status" name="status">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('record_status') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/meters')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>

    <?php if ($pager['data'] === []): ?>
        <div class="empty-state"><div class="empty-state__icon">📟</div><p><?= e(t('landing.meters.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th><?= e(t('common.fields.meter_code')) ?></th><th><?= e(t('common.fields.apartment')) ?></th><th><?= e(t('common.fields.utility_type')) ?></th><th><?= e(t('common.fields.usage_type')) ?></th><th><?= e(t('common.fields.tag')) ?></th><th class="num"><?= e(t('common.fields.initial_reading')) ?></th><th class="num"><?= t('ui.periods_with_readings') ?></th><th><?= e(t('common.fields.status')) ?></th><th class="actions"><?= e(t('common.fields.actions')) ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pager['data'] as $row): ?>
                    <tr class="<?= $row['status'] === 'ngung' ? 'row-muted' : '' ?>">
                        <td><a href="<?= e(base_url('/meters/' . $row['id'])) ?>"><strong><?= e($row['meter_code']) ?></strong></a></td>
                        <td><?= e($row['building_code'] . ' / ' . $row['apartment_code']) ?></td>
                        <td><?= e(label_of('utility_type', $row['utility_type'])) ?></td>
                        <td><?= e(label_of('usage_type', $row['usage_type'])) ?></td>
                        <td><?= e($row['display_label'] ?? '—') ?></td>
                        <td class="num"><?= e(format_decimal($row['initial_reading'])) ?></td>
                        <td class="num"><?= (int) $row['reading_count'] ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions">
                            <a href="<?= e(base_url('/meters/' . $row['id'])) ?>"><?= e(t('common.actions.details')) ?></a>
                            <?php if (can('meters.update')): ?>
                                <a class="action-edit" href="<?= e(base_url('/meters/' . $row['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php $basePath = '/meters'; require BASE_PATH . '/resources/views/partials/pagination.php'; ?>
</div>
