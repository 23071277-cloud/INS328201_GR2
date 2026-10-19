<?php
/** @var array $r UtilityReportService::build @var array $options */
$f      = $r['filters'];
$p      = $r['period'];
$show   = $r['show'];
$units  = config('pricing.utility_unit');
$uLabel = config('alerts.utility_word');
$states = \App\Services\UtilityReportService::stateLabels();
$stateClass = [
    'official'    => 'badge--active',
    'unconfirmed' => 'badge--warning',
    'stale'       => 'badge--danger',
    'missing'     => 'badge--muted',
];
$sel = function (string $name, array $opts, string $current, string $all = ''): string {
    $html = '<select id="' . e($name) . '" name="' . e($name) . '">'
          . ($all !== '' ? '<option value="">' . e($all) . '</option>' : '');
    foreach ($opts as $v => $l) {
        $html .= '<option value="' . e((string) $v) . '"'
               . ((string) $v === $current ? ' selected' : '') . '>'
               . e($l) . '</option>';
    }
    return $html . '</select>';
};
$m = static fn (int $c) => money_vnd(\App\Core\Decimal::fromCents($c));
?>

<!-- Page header -->
<div class="page-head">
    <div>
        <h1 class="page-title">
            <?= e(t('page.reports')) ?><?= $p ? e(t('landing.period_suffix', ['code' => $p['period_code']])) : '' ?>
        </h1>
    </div>
    <div class="page-head__actions no-print">
        <?php if (can('reports.export') && $p): ?>
            <a class="btn rpt-btn-export" href="<?= e(url_with('/reports/utility/export', $f)) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?= e(t('common.actions.export_csv')) ?>
            </a>
        <?php endif; ?>
        <button type="button" class="btn rpt-btn-print" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            <?= e(t('common.actions.print')) ?>
        </button>
    </div>
</div>

<!-- Filter card -->
<div class="rpt-filter-card no-print">
    <form method="get" action="<?= e(base_url('/reports/utility')) ?>" class="rpt-filter-form">
        <div class="rpt-filter-form__fields">
            <div class="form-group">
                <label for="period_id"><?= e(t('common.fields.period')) ?></label>
                <?= $sel('period_id', array_column($options['periods'], 'period_code', 'id'), $f['period_id']) ?>
            </div>
            <div class="form-group">
                <label for="building_id"><?= e(t('ui.building')) ?></label>
                <?= $sel('building_id', array_column($options['buildings'], 'code', 'id'), $f['building_id']) ?>
            </div>
            <div class="form-group">
                <label for="zone_id">Khu</label>
                <?= $sel('zone_id', array_column($options['zones'], 'name', 'id'), $f['zone_id'], t('dashboard.whole_building')) ?>
            </div>
            <div class="form-group">
                <label for="utility_type"><?= e(t('common.fields.utility')) ?></label>
                <?= $sel('utility_type', master_labels('utility_type'), $f['utility_type'], t('lit.electricity_and_water')) ?>
            </div>
            <div class="form-group">
                <label for="apartment"><?= e(t('common.fields.apartment_code_short')) ?></label>
                <input id="apartment" name="apartment" value="<?= e($f['apartment']) ?>" placeholder="—">
            </div>
        </div>
        <div class="rpt-filter-form__action">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn--primary rpt-filter-form__btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Xem
            </button>
        </div>
    </form>
</div>

<!-- Data card -->
<div class="rpt-data-card">
    <?php if ($p === null || $r['rows'] === []): ?>
        <div class="dash-empty-state">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p><?= e(t('landing.reports.empty')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap rpt-table-wrap">
            <table class="data-table rpt-table">
                <thead>
                    <tr>
                        <th><?= e(t('common.fields.apartment')) ?></th>
                        <th><?= e(t('lit.zone_short')) ?></th>
                        <?php if ($show['names']): ?>
                            <th><?= e(t('ui.owner')) ?></th>
                        <?php endif; ?>
                        <?php foreach ($r['utilities'] as $u): ?>
                            <th class="num">
                                <?= e(t('lit.consumption_col', ['utility' => label_t('alerts.utility_word.' . $u, $uLabel[$u]), 'unit' => $units[$u]])) ?>
                            </th>
                        <?php endforeach; ?>
                        <?php if ($show['cost']): ?>
                            <th class="num"><?= e(t('ui.electricity_charges_incl_vat')) ?></th>
                            <th class="num"><?= e(t('billing_show.water_amount')) ?></th>
                            <th class="num"><?= e(t('common.fields.fixed_fee')) ?></th>
                            <th class="num"><?= e(t('common.fields.total_charges')) ?></th>
                            <th class="num"><?= e(t('common.fields.old_debt')) ?></th>
                            <th class="num"><?= e(t('common.fields.amount_due')) ?></th>
                            <th><?= e(t('common.fields.charge')) ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($r['rows'] as $row): $c = $row['charge']; ?>
                    <tr class="rpt-row">
                        <td class="rpt-td-apt"><?= e($row['apartment']['building_code'] . ' / ' . $row['apartment']['code']) ?></td>
                        <td class="rpt-td-zone"><?= e($row['apartment']['zone_name'] ?? '—') ?></td>
                        <?php if ($show['names']): ?>
                            <td class="rpt-td-owner"><?= e($row['owner'] ?? '—') ?></td>
                        <?php endif; ?>
                        <?php foreach ($r['utilities'] as $u): $mm = $row['consumption'][$u]; ?>
                            <td class="num rpt-td-num">
                                <?php if ($mm['value'] === null): ?>
                                    <?php if ($mm['status'] === 'no_data'): ?>
                                        <span class="rpt-dash">—</span>
                                    <?php else: ?>
                                        <span class="rpt-insufficient"><?= e(t('lit.insufficient')) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= e(format_decimal(\App\Core\Decimal::fromCents($mm['value']))) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <?php if ($show['cost']): ?>
                            <?php if ($c === null): ?>
                                <td colspan="6" class="rpt-td-num"><span class="rpt-dash">—</span></td>
                            <?php else: ?>
                                <td class="num rpt-td-num"><?= e($m(\App\Services\MoneyCalculator::fromDb($c['electricity_amount']) + \App\Services\MoneyCalculator::fromDb($c['electricity_vat_amount']))) ?></td>
                                <td class="num rpt-td-num"><?= e(money_vnd($c['water_amount'])) ?></td>
                                <td class="num rpt-td-num"><?= e($m(\App\Services\MoneyCalculator::fromDb($c['service_fee_amount']) + \App\Services\MoneyCalculator::fromDb($c['fire_insurance_amount']) + \App\Services\MoneyCalculator::fromDb($c['parking_fee_amount']))) ?></td>
                                <td class="num rpt-td-num"><?= e(money_vnd($c['total_arising'])) ?></td>
                                <td class="num rpt-td-num"><?= e(money_vnd($c['old_debt_amount'])) ?></td>
                                <td class="num rpt-td-num rpt-td-total"><strong><?= e(money_vnd($c['total_due'])) ?></strong></td>
                            <?php endif; ?>
                            <td>
                                <span class="badge <?= e($stateClass[$row['charge_state']]) ?>"><?= e(label_t('report.charge_state.' . $row['charge_state'], $states[$row['charge_state']])) ?></span>
                                <?php if ($c !== null && can('billing.view')): ?>
                                    <a class="no-print rpt-detail-link" href="<?= e(base_url('/charges/' . $c['id'])) ?>"><?= e(t('ui.details')) ?></a>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php $t = $r['totals']; ?>
                <tfoot>
                    <tr class="rpt-tfoot-row">
                        <th class="rpt-tfoot-label" colspan="<?= 2 + ($show['names'] ? 1 : 0) ?>">
                            <?= e(t('ui.total')) ?> <?= count($r['rows']) ?> <?= e(t('ui.apartments')) ?>
                        </th>
                        <?php foreach ($r['utilities'] as $u): ?>
                            <th class="num rpt-tfoot-num">
                                <?= e(format_decimal(\App\Core\Decimal::fromCents($t['consumption'][$u]))) ?> <?= e($units[$u]) ?>
                                <?php if ($t['incomplete'][$u]): ?>
                                    <div class="rpt-tfoot-hint"><?= (int) $t['incomplete'][$u] ?> <?= e(t('ui.apartments')) ?> <?= e(t('ui.incomplete_data_suffix')) ?></div>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                        <?php if ($show['cost']): ?>
                            <th class="num rpt-tfoot-num"><?= e($m($t['electricity_cost'])) ?></th>
                            <th class="num rpt-tfoot-num"><?= e($m($t['water_cost'])) ?></th>
                            <th class="num rpt-tfoot-num"><?= e($m($t['fixed_fee'])) ?></th>
                            <th class="num rpt-tfoot-num"><?= e($m($t['total_arising'])) ?></th>
                            <th class="num rpt-tfoot-num" colspan="3">
                                <?php if ($t['official'] > 0 || $t['excluded'] > 0): ?>
                                    <span class="rpt-tfoot-info" tabindex="0">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                        <span class="rpt-tfoot-tooltip">
                                            <?= e(t('ui.only')) ?> <?= (int) $t['official'] ?> <?= e(t('ui.official_bills_are_added_excluded')) ?> <?= (int) $t['excluded'] ?> <?= e(t('ui.not_confirmed_needs_recalculation_none')) ?>
                                        </span>
                                    </span>
                                <?php endif; ?>
                            </th>
                        <?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
