<?php
/** @var array $filters @var array $pager @var array $buildingOptions */
$breadcrumbs = [[t('crumbs.catalog'), null], [t('page.zones'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.zones')) ?></h1>
        <p class="page-subtitle"><?= e(t('landing.zones.subtitle')) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('zones.create')): ?>
            <a class="btn btn--primary" href="<?= e(url_with('/zones/create', ['building_id' => $filters['building_id']])) ?>"><?= e(t('common.actions.add_zone')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <form method="get" action="<?= e(base_url('/zones')) ?>" class="toolbar">
        <div class="form-group form-group--grow">
            <label for="q"><?= e(t('common.fields.search')) ?></label>
            <input type="text" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('common.placeholders.zone_search')) ?>">
        </div>
        <div class="form-group">
            <label for="building_id"><?= e(t('common.fields.building')) ?></label>
            <select id="building_id" name="building_id">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach ($buildingOptions as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= $filters['building_id'] === (string) $b['id'] ? 'selected' : '' ?>><?= e($b['code'] . ' — ' . $b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="zone_type"><?= e(t('common.fields.zone_type')) ?></label>
            <select id="zone_type" name="zone_type">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('zone_type') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['zone_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
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
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/zones')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>

    <?php if ($pager['data'] === []): ?>
        <div class="empty-state"><div class="empty-state__icon">🗺️</div><p><?= e(t('landing.zones.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th><?= e(t('common.fields.zone_code')) ?></th><th><?= t('ui.zone_name') ?></th><th><?= e(t('common.fields.building')) ?></th><th><?= e(t('common.fields.zone_type')) ?></th><th class="num"><?= e(t('common.fields.apartment')) ?></th><th><?= e(t('common.fields.status')) ?></th><th class="actions"><?= e(t('common.fields.actions')) ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pager['data'] as $row): ?>
                    <tr class="<?= $row['status'] === 'ngung' ? 'row-muted' : '' ?>">
                        <td><a href="<?= e(base_url('/zones/' . $row['id'])) ?>"><strong><?= e($row['code']) ?></strong></a></td>
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['building_code']) ?></td>
                        <td><?= e(label_of('zone_type', $row['zone_type'])) ?></td>
                        <td class="num"><?= (int) $row['apartment_count'] ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions">
                            <a href="<?= e(base_url('/zones/' . $row['id'])) ?>"><?= e(t('common.actions.details')) ?></a>
                            <?php if (can('zones.update')): ?>
                                <a class="action-edit" href="<?= e(base_url('/zones/' . $row['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php $basePath = '/zones'; require BASE_PATH . '/resources/views/partials/pagination.php'; ?>
</div>
