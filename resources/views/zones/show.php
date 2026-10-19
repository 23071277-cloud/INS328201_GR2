<?php
/** @var array $zone (kèm apartments) */
$breadcrumbs = [[t('menu.groups.catalog'), null], [t('menu.zones'), '/zones'], [$zone['code'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$isActive = $zone['status'] === 'hoat_dong';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($zone['code']) ?> — <?= e($zone['name']) ?></h1>
        <p class="page-subtitle"><?= status_badge($zone['status']) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('zones.update')): ?>
            <a class="action-edit btn btn--ghost" href="<?= e(base_url('/zones/' . $zone['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
        <?php endif; ?>
        <?php if ($isActive && can('zones.deactivate')): ?>
            <?php
            $deactivateAction = '/zones/' . $zone['id'] . '/deactivate';
            $deactivateMessage = t('lit.deactivate_zone') . $zone['code'] . t('lit.it_can_only_be_deactivated_when_2');
            $deactivateLabel = t('lit.deactivate_zone_2');
            require BASE_PATH . '/resources/views/partials/deactivate_form.php';
            ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('common.fields.zone_code') ?></dt><dd><?= e($zone['code']) ?></dd></div>
        <div><dt><?= t('ui.zone_name') ?></dt><dd><?= e($zone['name']) ?></dd></div>
        <div>
            <dt><?= t('menu.buildings') ?></dt>
            <dd>
                <?php if (can('buildings.view')): ?>
                    <a href="<?= e(base_url('/buildings/' . $zone['building_id'])) ?>"><?= e($zone['building_code'] . ' — ' . $zone['building_name']) ?></a>
                <?php else: ?>
                    <?= e($zone['building_code'] . ' — ' . $zone['building_name']) ?>
                <?php endif; ?>
                <?= $zone['building_status'] === 'ngung' ? status_badge('ngung') : '' ?>
            </dd>
        </div>
        <div><dt><?= t('common.fields.zone_type') ?></dt><dd><?= e(label_of('zone_type', $zone['zone_type'])) ?></dd></div>
        <div><dt><?= t('ui.last_updated') ?></dt><dd><?= e($zone['updated_at']) ?></dd></div>
    </dl>
</div>

<div class="panel">
    <div class="panel__head">
        <h2 class="panel__title"><?= t('ui.apartments_in_this_zone') ?><?= count($zone['apartments']) ?>)</h2>
        <?php if ($isActive && can('apartments.create')): ?>
            <a class="btn btn--primary btn--sm" href="<?= e(url_with('/apartments/create', ['building_id' => $zone['building_id']])) ?>"><?= e(t('common.actions.add_apartment')) ?></a>
        <?php endif; ?>
    </div>
    <?php if ($zone['apartments'] === []): ?>
        <div class="empty-state"><p><?= t('ui.this_zone_has_no_apartments_yet') ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.apartment_code')) ?></th><th class="num"><?= e(t('common.fields.area')) ?></th><th><?= e(t('common.fields.occupancy')) ?></th><th><?= e(t('common.fields.status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($zone['apartments'] as $apt): ?>
                    <tr class="<?= $apt['status'] === 'ngung' ? 'row-muted' : '' ?>">
                        <td>
                            <?php if (can('apartments.view')): ?>
                                <a href="<?= e(base_url('/apartments/' . $apt['id'])) ?>"><?= e($apt['code']) ?></a>
                            <?php else: ?>
                                <?= e($apt['code']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= e(format_decimal($apt['area'])) ?></td>
                        <td><?= e(label_of('occupancy_status', $apt['occupancy_status'])) ?></td>
                        <td><?= status_badge($apt['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
