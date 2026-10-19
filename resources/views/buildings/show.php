<?php
/** @var array $building (kèm zones, apartment_stats) */
$breadcrumbs = [[t('menu.groups.catalog'), null], [t('menu.buildings'), '/buildings'], [$building['code'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$isActive = $building['status'] === 'hoat_dong';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($building['code']) ?> — <?= e($building['name']) ?></h1>
        <p class="page-subtitle"><?= status_badge($building['status']) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('buildings.update')): ?>
            <a class="action-edit btn btn--ghost" href="<?= e(base_url('/buildings/' . $building['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
        <?php endif; ?>
        <?php if ($isActive && can('buildings.deactivate')): ?>
            <?php
            $deactivateAction = '/buildings/' . $building['id'] . '/deactivate';
            $deactivateMessage = t('lit.deactivate_building') . $building['code'] . t('lit.it_can_only_be_deactivated_when');
            $deactivateLabel = t('lit.deactivate_building_2');
            require BASE_PATH . '/resources/views/partials/deactivate_form.php';
            ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('ui.building_code') ?></dt><dd><?= e($building['code']) ?></dd></div>
        <div><dt><?= t('common.fields.name') ?></dt><dd><?= e($building['name']) ?></dd></div>
        <div><dt><?= t('ui.apartments_active_total') ?></dt><dd><?= $building['apartment_stats']['active'] ?> / <?= $building['apartment_stats']['total'] ?></dd></div>
        <div><dt><?= t('ui.created_on') ?></dt><dd><?= e($building['created_at']) ?></dd></div>
        <div><dt><?= t('ui.last_updated') ?></dt><dd><?= e($building['updated_at']) ?></dd></div>
    </dl>
</div>

<div class="panel">
    <div class="panel__head">
        <h2 class="panel__title"><?= t('ui.zones_in_this_building') ?><?= count($building['zones']) ?>)</h2>
        <div class="page-head__actions">
            <?php if (can('apartments.view')): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url_with('/apartments', ['building_id' => $building['id']])) ?>"><?= t('ui.view_apartments_in_this_building') ?></a>
            <?php endif; ?>
            <?php if ($isActive && can('zones.create')): ?>
                <a class="btn btn--primary btn--sm" href="<?= e(url_with('/zones/create', ['building_id' => $building['id']])) ?>"><?= e(t('common.actions.add_zone')) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($building['zones'] === []): ?>
        <div class="empty-state"><p><?= t('ui.this_building_has_no_zones_yet') ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.zone_code')) ?></th><th><?= e(t('common.fields.name')) ?></th><th><?= e(t('common.fields.zone_type')) ?></th><th class="num"><?= e(t('common.fields.apartment')) ?></th><th><?= e(t('common.fields.status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($building['zones'] as $zone): ?>
                    <tr class="<?= $zone['status'] === 'ngung' ? 'row-muted' : '' ?>">
                        <td>
                            <?php if (can('zones.view')): ?>
                                <a href="<?= e(base_url('/zones/' . $zone['id'])) ?>"><?= e($zone['code']) ?></a>
                            <?php else: ?>
                                <?= e($zone['code']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e($zone['name']) ?></td>
                        <td><?= e(label_of('zone_type', $zone['zone_type'])) ?></td>
                        <td class="num"><?= (int) $zone['apartment_count'] ?></td>
                        <td><?= status_badge($zone['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
