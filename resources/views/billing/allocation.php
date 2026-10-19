<?php
/** @var array $period @var array $summary @var bool $confirmedOnly */
$breadcrumbs = [[t('period_show.billing_button'), null], [t('lit.period_2') . $period['period_code'], '/periods/' . $period['id']], [t('lit.cost_summary'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$statusLabels = array_map(static fn (string $s): string => config_labels('billing.charge_status')[$s] ?? $s, $summary['statuses']);
$levels = ['building' => t('lit.by_building'), 'zone' => t('lit.by_zone'), 'apartment' => t('lit.by_apartment')];
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.cost_summary_period') ?> <?= e($period['period_code']) ?></h1>
        <p class="page-subtitle"><?= t('ui.fn_05_05_cost_period_total') ?> <?= e(implode(', ', $statusLabels)) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--ghost btn--sm" href="<?= e(url_with('/periods/' . $period['id'] . '/cost-allocation', $confirmedOnly ? ['scope' => 'all'] : [])) ?>"><?= $confirmedOnly ? t('lit.include_unconfirmed_bills') : t('lit.confirmed_bills_only_br_11') ?></a>
    </div>
</div>

<?php if (!$confirmedOnly): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= t('ui.includes_bills_that_are') ?> <strong><?= t('ui.not_confirmed') ?></strong> <?= t('ui.for_monitoring_only_official_figures_use') ?></div>
<?php endif; ?>

<?php foreach ($levels as $level => $label): ?>
    <div class="panel">
        <div class="panel__head"><h2 class="panel__title"><?= e($label) ?></h2></div>
        <?php if ($summary[$level] === []): ?>
            <div class="empty-state"><p><?= t('ui.no_matching_bills') ?></p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr>
                        <th><?= $level === 'apartment' ? t('menu.apartments') : ($level === 'zone' ? t('menu.zones') : t('menu.buildings')) ?></th>
                        <?php if ($level !== 'building'): ?><th><?= t('ui.building') ?></th><?php endif; ?>
                        <th class="num"><?= t('ui.apartments_3') ?></th><th class="num"><?= t('ui.electricity_consumption_kwh') ?></th><th class="num"><?= t('ui.water_consumption_m_3') ?></th>
                        <th class="num"><?= t('ui.electricity_cost') ?></th><th class="num"><?= t('ui.water_cost') ?></th><th class="num"><?= e(t('common.fields.fixed_fee')) ?></th><th class="num"><?= t('ui.total_cost') ?></th>
                        <?php if ($level !== 'apartment'): ?><th class="num"><?= t('ui.avg_apartment') ?></th><?php endif; ?>
                        <th class="num"><?= t('ui.cost_m_2') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($summary[$level] as $row): ?>
                        <tr>
                            <td><?= e($row['group_code'] !== '' ? $row['group_code'] : t('lit.no_zone')) ?><?= $level === 'apartment' ? ' ' . charge_badge($row['charge_status']) : '' ?></td>
                            <?php if ($level !== 'building'): ?><td><?= e($row['building_code']) ?></td><?php endif; ?>
                            <td class="num"><?= (int) $row['charged_apartments'] ?></td>
                            <td class="num"><?= e(format_decimal($row['electricity_consumption'])) ?></td>
                            <td class="num"><?= e(format_decimal($row['water_consumption'])) ?></td>
                            <td class="num"><?= e(money_vnd($row['electricity_cost'])) ?></td>
                            <td class="num"><?= e(money_vnd($row['water_cost'])) ?></td>
                            <td class="num"><?= e(money_vnd($row['fixed_fee_cost'])) ?></td>
                            <td class="num"><strong><?= e(money_vnd($row['total_cost'])) ?></strong></td>
                            <?php if ($level !== 'apartment'): ?><td class="num"><?= e(money_vnd($row['average_per_apartment'], true)) ?></td><?php endif; ?>
                            <td class="num"><?= $row['cost_per_m2'] === null ? '—' : e(money_vnd($row['cost_per_m2'], true)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<p class="form-hint"><?= t('ui.allocating_the_master_invoice_common_area') ?> <?= t('ui.the_documents_have_no_common_cost') ?></p>
