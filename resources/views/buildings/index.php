<?php
/** @var array $filters @var array $pager */
$breadcrumbs = [[t('crumbs.catalog'), null], [t('page.buildings'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.buildings')) ?></h1>
        <p class="page-subtitle"><?= e(t('landing.buildings.subtitle')) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('buildings.create')): ?>
            <a class="btn btn--primary" href="<?= e(base_url('/buildings/create')) ?>"><?= e(t('common.actions.add_building')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <form method="get" action="<?= e(base_url('/buildings')) ?>" class="toolbar">
        <div class="form-group form-group--grow">
            <label for="q"><?= e(t('common.fields.search')) ?></label>
            <input type="text" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('common.placeholders.building_search')) ?>">
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
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('/buildings')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>

    <?php if ($pager['data'] === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon">🏢</div>
            <p><?= e(t('landing.buildings.empty')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th><?= e(t('common.fields.code')) ?></th>
                    <th><?= e(t('common.fields.building_name')) ?></th>
                    <th class="num"><?= e(t('common.fields.zone')) ?></th>
                    <th class="num"><?= e(t('common.fields.apartment')) ?></th>
                    <th><?= e(t('common.fields.status')) ?></th>
                    <th class="actions"><?= e(t('common.fields.actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pager['data'] as $row): ?>
                    <tr class="<?= $row['status'] === 'ngung' ? 'row-muted' : '' ?>">
                        <td><a href="<?= e(base_url('/buildings/' . $row['id'])) ?>"><strong><?= e($row['code']) ?></strong></a></td>
                        <td><?= e($row['name']) ?></td>
                        <td class="num"><?= (int) $row['zone_count'] ?></td>
                        <td class="num"><?= (int) $row['apartment_count'] ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions">
                            <a href="<?= e(base_url('/buildings/' . $row['id'])) ?>"><?= e(t('common.actions.details')) ?></a>
                            <?php if (can('buildings.update')): ?>
                                <a class="action-edit" href="<?= e(base_url('/buildings/' . $row['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php $basePath = '/buildings'; require BASE_PATH . '/resources/views/partials/pagination.php'; ?>
</div>
