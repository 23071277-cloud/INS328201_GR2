<?php
/** @var array $apartment (kèm meters, residents, vehicles) */
$breadcrumbs = [[t('menu.groups.catalog'), null], [t('menu.apartments'), '/apartments'], [$apartment['code'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$isActive = $apartment['status'] === 'hoat_dong';
$canSeePhone = can('residents.view_phone');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('menu.apartments') ?> <?= e($apartment['code']) ?></h1>
        <p class="page-subtitle"><?= status_badge($apartment['status']) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('apartments.update')): ?>
            <a class="action-edit btn btn--ghost" href="<?= e(base_url('/apartments/' . $apartment['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
        <?php endif; ?>
        <?php if ($isActive && can('apartments.deactivate')): ?>
            <?php
            $deactivateAction = '/apartments/' . $apartment['id'] . '/deactivate';
            $deactivateMessage = t('lit.deactivate_apartment') . $apartment['code'] . t('lit.it_can_only_be_deactivated_when_3');
            $deactivateLabel = t('lit.deactivate_apartment_2');
            require BASE_PATH . '/resources/views/partials/deactivate_form.php';
            ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('common.fields.apartment_code') ?></dt><dd><?= e($apartment['code']) ?></dd></div>
        <div>
            <dt><?= t('menu.buildings') ?></dt>
            <dd>
                <?php if (can('buildings.view')): ?>
                    <a href="<?= e(base_url('/buildings/' . $apartment['building_id'])) ?>"><?= e($apartment['building_code'] . ' — ' . $apartment['building_name']) ?></a>
                <?php else: ?>
                    <?= e($apartment['building_code'] . ' — ' . $apartment['building_name']) ?>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt><?= t('menu.zones') ?></dt>
            <dd>
                <?php if ($apartment['zone_id'] === null): ?>
                    —
                <?php elseif (can('zones.view')): ?>
                    <a href="<?= e(base_url('/zones/' . $apartment['zone_id'])) ?>"><?= e($apartment['zone_code'] . ' — ' . $apartment['zone_name']) ?></a>
                <?php else: ?>
                    <?= e($apartment['zone_code'] . ' — ' . $apartment['zone_name']) ?>
                <?php endif; ?>
            </dd>
        </div>
        <div><dt><?= t('ui.area') ?></dt><dd><?= e(format_decimal($apartment['area'])) ?> m²</dd></div>
        <div><dt><?= t('common.fields.occupancy') ?></dt><dd><?= e(label_of('occupancy_status', $apartment['occupancy_status'])) ?></dd></div>
        <div><dt><?= t('ui.last_updated') ?></dt><dd><?= e($apartment['updated_at']) ?></dd></div>
    </dl>
</div>

<?php if (can('meters.view')): ?>
<div class="panel" id="meters">
    <div class="panel__head">
        <h2 class="panel__title"><?= t('ui.meters') ?><?= count($apartment['meters']) ?>)</h2>
        <?php if ($isActive && can('meters.create')): ?>
            <a class="btn btn--primary btn--sm" href="<?= e(url_with('/meters/create', ['apartment_id' => $apartment['id']])) ?>"><?= e(t('common.actions.add_meter')) ?></a>
        <?php endif; ?>
    </div>
    <?php if ($apartment['meters'] === []): ?>
        <div class="empty-state"><p><?= t('ui.this_apartment_has_no_meters_yet') ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.meter_code')) ?></th><th><?= e(t('common.fields.utility_type')) ?></th><th><?= e(t('common.fields.usage_type')) ?></th><th><?= e(t('common.fields.tag')) ?></th><th class="num"><?= e(t('common.fields.initial_reading')) ?></th><th class="num"><?= t('ui.periods_with_readings') ?></th><th><?= e(t('common.fields.status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($apartment['meters'] as $meter): ?>
                    <tr class="<?= $meter['status'] === 'ngung' ? 'row-muted' : '' ?>">
                        <td><a href="<?= e(base_url('/meters/' . $meter['id'])) ?>"><?= e($meter['meter_code']) ?></a></td>
                        <td><?= e(label_of('utility_type', $meter['utility_type'])) ?></td>
                        <td><?= e(label_of('usage_type', $meter['usage_type'])) ?></td>
                        <td><?= e($meter['display_label'] ?? '—') ?></td>
                        <td class="num"><?= e(format_decimal($meter['initial_reading'])) ?></td>
                        <td class="num"><?= (int) $meter['reading_count'] ?></td>
                        <td><?= status_badge($meter['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (can('residents.view')): ?>
<div class="panel" id="residents">
    <div class="panel__head">
        <h2 class="panel__title"><?= t('ui.owners_tenants') ?><?= count($apartment['residents']) ?>)</h2>
        <?php if ($isActive && can('residents.create')): ?>
            <a class="btn btn--primary btn--sm" href="<?= e(base_url('/apartments/' . $apartment['id'] . '/residents/create')) ?>"><?= e(t('common.actions.add')) ?></a>
        <?php endif; ?>
    </div>
    <?php if (!$canSeePhone): ?>
        <p class="form-hint"><?= t('ui.phone_numbers_are_personal_data_nfr') ?></p>
    <?php endif; ?>
    <?php if ($apartment['residents'] === []): ?>
        <div class="empty-state"><p><?= t('ui.no_owners_tenants_yet') ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.full_name')) ?></th><th><?= e(t('common.fields.role')) ?></th><th><?= e(t('common.fields.phone')) ?></th><th>Email</th><th><?= e(t('common.fields.effective_from')) ?></th><th><?= t('ui.to_2') ?></th><th><?= e(t('common.fields.status')) ?></th><th class="actions"><?= e(t('common.fields.actions')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($apartment['residents'] as $resident): ?>
                    <?php $residentActive = (int) $resident['is_active'] === 1; ?>
                    <tr class="<?= $residentActive ? '' : 'row-muted' ?>">
                        <td><?= e($resident['full_name']) ?></td>
                        <td><?= e(label_of('resident_role', $resident['resident_role'])) ?></td>
                        <td><?= e($canSeePhone ? ($resident['phone'] ?? '—') : mask_phone($resident['phone'])) ?></td>
                        <td><?= e($canSeePhone ? ($resident['email'] ?? '—') : mask_email($resident['email'] ?? null)) ?></td>
                        <td><?= e(format_date($resident['effective_from'])) ?></td>
                        <td><?= e(format_date($resident['effective_to'])) ?></td>
                        <td><span class="badge <?= $residentActive ? 'badge--active' : 'badge--inactive' ?>"><?= $residentActive ? t('lit.active') : t('lit.expired') ?></span></td>
                        <td class="actions">
                            <?php if (can('residents.update')): ?>
                                <a class="action-edit" href="<?= e(base_url('/residents/' . $resident['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
                            <?php endif; ?>
                            <?php if ($residentActive && can('residents.deactivate')): ?>
                                <?php
                                $deactivateAction = '/residents/' . $resident['id'] . '/deactivate';
                                $deactivateMessage = t('lit.end_the_validity_of') . $resident['full_name'] . t('lit.the_end_date_will_be_today');
                                $deactivateLabel = t('lit.end');
                                require BASE_PATH . '/resources/views/partials/deactivate_form.php';
                                ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (can('vehicles.view')): ?>
<div class="panel" id="vehicles">
    <div class="panel__head">
        <h2 class="panel__title"><?= t('ui.parked_vehicles') ?></h2>
        <?php if ($isActive && can('vehicles.update')): ?>
            <a class="action-edit btn btn--primary btn--sm" href="<?= e(base_url('/apartments/' . $apartment['id'] . '/vehicles/edit')) ?>"><?= t('ui.update_quantities') ?></a>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th><?= t('ui.vehicle_type') ?></th><th class="num"><?= t('ui.quantity') ?></th></tr></thead>
            <tbody>
            <?php foreach (master_labels('vehicle_type') as $type => $label): ?>
                <tr>
                    <td><?= e($label) ?></td>
                    <td class="num"><?= (int) ($apartment['vehicles'][$type]['quantity'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="form-hint"><?= t('ui.input_data_only_parking_fees_are') ?></p>
</div>
<?php endif; ?>
