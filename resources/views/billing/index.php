<?php
/** @var array $period @var array $rows @var ?array $blockers */
$breadcrumbs = [[t('period_show.billing_button'), null], [t('menu.periods'), '/periods'], [$period['period_code'], '/periods/' . $period['id']], [t('billing_show.crumb_billing'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$locked = $period['status'] === 'da_khoa';
$canCalculate = !$locked && can('billing.calculate');
$sum = ['total_arising' => 0, 'total_due' => 0, 'charged' => 0];
foreach ($rows as $row) {
    if ($row['charge'] !== null) {
        $sum['total_arising'] += \App\Services\MoneyCalculator::fromDb($row['charge']['total_arising']);
        $sum['total_due'] += \App\Services\MoneyCalculator::fromDb($row['charge']['total_due']);
        $sum['charged']++;
    }
}
$stateCount = array_count_values(array_column($rows, 'state'));
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.billing_period') ?> <?= e($period['period_code']) ?></h1>
        <p class="page-subtitle"><?= period_badge($period['status']) ?> <?= t('ui.fn_05_02_05_04_tariffs') ?> <?= e(format_date($period['end_date'])) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($canCalculate): ?>
            <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/billing/calculate')) ?>" class="inline-form js-confirm"
                  data-confirm-title="<?= t('ui.bill_the_whole_period') ?>" data-confirm-message="<?= t('ui.bill_every_apartment_in_the_period') ?>" data-confirm-button="<?= t('billing_show.crumb_billing') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary"><?= t('ui.bill_the_whole_period') ?></button>
            </form>
        <?php endif; ?>
        <?php if (!$locked && can('billing.confirm')): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('/periods/' . $period['id'] . '/billing/review')) ?>"><?= t('ui.review_confirm') ?></a>
        <?php endif; ?>
        <?php if (!$locked && can('billing.approve')): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('/periods/' . $period['id'] . '/billing/approvals')) ?>"><?= t('ui.bql_approval_optional') ?></a>
        <?php endif; ?>
        <?php if (can('bills.view')): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('/periods/' . $period['id'] . '/notices')) ?>"><?= t('ui.print_all_notices_for_the_period') ?></a>
        <?php endif; ?>
        <?php if (can('cost_allocation.view')): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('/periods/' . $period['id'] . '/cost-allocation')) ?>"><?= t('ui.cost_summary') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($locked): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= t('ui.period_locked_bills_are_historical_data') ?></div>
<?php endif; ?>

<div class="card-grid">
    <div class="stat-card"><div class="stat-card__label"><?= t('ui.apartments_billed') ?></div><div class="stat-card__value"><?= count($rows) ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= t('ui.bills_created') ?></div><div class="stat-card__value"><?= $sum['charged'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= t('ui.needs_recalculation_error') ?></div><div class="stat-card__value"><?= ($stateCount['stale'] ?? 0) + ($stateCount['stale_error'] ?? 0) + ($stateCount['error'] ?? 0) ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= t('ui.total_charges_total_row') ?></div><div class="stat-card__value"><?= e(money_vnd($sum['total_arising'], true)) ?></div></div>
</div>

<?php if ($blockers !== null): ?>
    <div class="panel billing-demo-hide">
        <div class="panel__head"><h2 class="panel__title"><?= t('period_show.lock_period') ?></h2></div>
        <?php if ($blockers === []): ?>
            <p><?= t('ui.ready_to_lock_lock_it_on') ?> <a href="<?= e(base_url('/periods/' . $period['id'])) ?>"><?= t('ui.period_details_page') ?></a>.</p>
        <?php else: ?>
            <ul class="rule-list"><?php foreach ($blockers as $reason): ?><li><?= e(message_t($reason)) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= t('ui.bills_by_apartment') ?></h2></div>
    <?php if ($rows === []): ?>
        <div class="empty-state"><p><?= t('ui.no_apartments_to_bill') ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th><?= e(t('common.fields.apartment')) ?></th><th>Khu</th><th class="num"><?= e(t('common.fields.consumption_elec')) ?></th><th class="num"><?= t('ui.electricity_incl_vat') ?></th><th class="num"><?= e(t('common.fields.consumption_water')) ?></th><th class="num"><?= t('ui.water') ?></th>
                    <th class="num"><?= e(t('common.fields.fixed_fee')) ?></th><th class="num"><?= e(t('common.fields.total_charges')) ?></th><th class="num"><?= e(t('common.fields.old_debt')) ?></th><th class="num"><?= e(t('common.fields.total_due')) ?></th>
                    <th><?= e(t('common.fields.status')) ?></th><th><?= e(t('common.fields.consistency')) ?></th><th class="actions"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $a = $row['apartment']; $c = $row['charge']; ?>
                    <tr class="<?= in_array($row['state'], ['stale', 'stale_error', 'error'], true) ? 'row-error' : '' ?>">
                        <td><?= e($a['building_code'] . ' / ' . $a['code']) ?></td>
                        <td><?= e($a['zone_code'] ?? '—') ?></td>
                        <?php if ($c !== null): ?>
                            <td class="num"><?= e(format_decimal($c['electricity_consumption'])) ?></td>
                            <td class="num"><?= e(money_vnd(\App\Services\MoneyCalculator::fromDb($c['electricity_amount']) + \App\Services\MoneyCalculator::fromDb($c['electricity_vat_amount']), true)) ?></td>
                            <td class="num"><?= e(format_decimal($c['water_consumption'])) ?></td>
                            <td class="num"><?= e(money_vnd($c['water_amount'])) ?></td>
                            <td class="num"><?= e(money_vnd(\App\Services\MoneyCalculator::fromDb($c['service_fee_amount']) + \App\Services\MoneyCalculator::fromDb($c['fire_insurance_amount']) + \App\Services\MoneyCalculator::fromDb($c['parking_fee_amount']), true)) ?></td>
                            <td class="num"><strong><?= e(money_vnd($c['total_arising'])) ?></strong></td>
                            <td class="num"><?= e(money_vnd($c['old_debt_amount'])) ?></td>
                            <td class="num"><strong><?= e(money_vnd($c['total_due'])) ?></strong></td>
                            <td><?= charge_badge($c['status']) ?></td>
                        <?php else: ?>
                            <td colspan="8" class="row-muted">—</td><td>—</td>
                        <?php endif; ?>
                        <td>
                            <?= consistency_badge($row['state']) ?>
                            <?php foreach (array_slice($row['reasons'], 0, 3) as $reason): ?><div class="form-hint"><?= e(message_t($reason)) ?></div><?php endforeach; ?>
                            <?php foreach ($row['warnings'] as $warning): ?><div class="form-hint"> <?= e(message_t($warning)) ?></div><?php endforeach; ?>
                        </td>
                        <td class="actions">
                            <?php if ($c !== null): ?><a href="<?= e(base_url('/charges/' . $c['id'])) ?>"><?= e(t('common.actions.details')) ?></a><?php endif; ?>
                            <?php if ($canCalculate && $row['state'] !== 'current' && ($c === null || in_array($c['status'], config('billing.recalculable_statuses'), true))): ?>
                                <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . '/billing/calculate')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="apartment_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="btn btn--link"><?= $c === null ? t('lit.calculate') : t('billing_show.recalc') ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="7"><?= t('ui.building_total') ?><?= $sum['charged'] ?> <?= t('ui.bills') ?></th><th class="num"><?= e(money_vnd($sum['total_arising'], true)) ?></th><th></th><th class="num"><?= e(money_vnd($sum['total_due'], true)) ?></th><th colspan="3"></th></tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
    <p class="form-hint"><?= t('ui.consistency_compares_the_stored_bill_with') ?> <strong><?= t('ui.needs_recalculation') ?></strong> <?= t('ui.cannot_be_confirmed_approved_or_locked') ?>.</p>
</div>
