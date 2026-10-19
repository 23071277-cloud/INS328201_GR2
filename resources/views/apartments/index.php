<?php
/** @var array $filters @var array $pager @var array $buildingOptions @var array $zoneOptions */
$breadcrumbs = [[t('crumbs.catalog'), null], [t('page.apartments'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.apartments')) ?></h1>
        <p class="page-subtitle"><?= e(t('landing.apartments.subtitle')) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('apartments.create')): ?>
            <a class="btn btn--primary" href="<?= e(url_with('/apartments/create', ['building_id' => $filters['building_id']])) ?>"><?= e(t('common.actions.add_apartment')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <form method="get" action="<?= e(base_url('/apartments')) ?>" class="toolbar">
        <div class="form-group form-group--grow">
            <label for="q"><?= e(t('common.fields.apartment_code')) ?></label>
            <input type="text" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="VD: LK2, S01, 2305">
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
            <label for="zone_id"><?= e(t('common.fields.zone')) ?></label>
            <select id="zone_id" name="zone_id">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach ($zoneOptions as $z): ?>
                    <option value="<?= (int) $z['id'] ?>" <?= $filters['zone_id'] === (string) $z['id'] ? 'selected' : '' ?>><?= e($z['building_code'] . ' / ' . $z['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="occupancy_status"><?= e(t('common.fields.occupancy')) ?></label>
            <select id="occupancy_status" name="occupancy_status">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach (master_labels('occupancy_status') as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['occupancy_status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
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
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/apartments')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>

    <?php if ($pager['data'] === []): ?>
        <div class="empty-state"><div class="empty-state__icon">🏠</div><p><?= e(t('landing.apartments.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th><?= e(t('common.fields.apartment_code')) ?></th><th><?= e(t('common.fields.building')) ?></th><th><?= e(t('common.fields.zone')) ?></th><th class="num"><?= e(t('common.fields.area')) ?></th><th><?= e(t('common.fields.occupancy')) ?></th><th class="num"><?= t('ui.active_meters') ?></th><th><?= e(t('common.fields.status')) ?></th><th class="actions"><?= e(t('common.fields.actions')) ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pager['data'] as $row): ?>
                    <tr class="<?= $row['status'] === 'ngung' ? 'row-muted' : '' ?>">
                        <td><a href="<?= e(base_url('/apartments/' . $row['id'])) ?>"><strong><?= e($row['code']) ?></strong></a></td>
                        <td><?= e($row['building_code']) ?></td>
                        <td><?= e($row['zone_code'] ?? '—') ?></td>
                        <td class="num"><?= e(format_decimal($row['area'])) ?></td>
                        <td><?= e(label_of('occupancy_status', $row['occupancy_status'])) ?></td>
                        <td class="num"><?= (int) $row['active_meter_count'] ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions">
                            <a href="<?= e(base_url('/apartments/' . $row['id'])) ?>"><?= e(t('common.actions.details')) ?></a>
                            <?php if (can('apartments.update')): ?>
                                <a class="action-edit" href="<?= e(base_url('/apartments/' . $row['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php $basePath = '/apartments'; require BASE_PATH . '/resources/views/partials/pagination.php'; ?>
</div>
