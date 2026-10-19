<?php
/** @var array $d chi tiết từ BillingCalculationService::detail @var array $form */
$c = $d['charge'];
$period = $d['period'];
$breadcrumbs = [[t('billing_show.crumb_billing_fn05'), null], [t('billing_show.crumb_period', ['code' => $c['period_code']]), '/periods/' . $c['billing_period_id']], [t('billing_show.crumb_billing'), '/periods/' . $c['billing_period_id'] . '/billing'], [$c['apartment_code'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$locked = $period['status'] === 'da_khoa';
$state = $d['consistency']['state'];
$current = $state === 'current';
$recalculable = in_array($c['status'], config('billing.recalculable_statuses'), true) && !\App\Services\IssuedChargeGuard::protected((int)$c['id']);
$feeTypes = config_labels('pricing.fee_type');
$units = config('pricing.utility_unit');
$snapshotMeters = [];
foreach ($d['snapshot']['meters'] ?? [] as $m) {
    $snapshotMeters[(int) $m['reading_id']] = $m;
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('billing_show.heading', ['apartment' => $c['building_code'] . ' / ' . $c['apartment_code'], 'code' => $c['period_code']])) ?></h1>
        <p class="page-subtitle"><?= charge_badge($c['status']) ?> <?= consistency_badge($state) ?> · DT <?= e(format_decimal($c['area'])) ?> m² · <?= e(label_of('occupancy_status', $c['occupancy_status'])) ?><?= $c['zone_code'] ? ' · khu ' . e($c['zone_code']) : '' ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('billing_adjustments.view')): ?><a class="btn" href="<?= e(base_url('/charges/'.$c['id'].'/adjustments')) ?>"><?= e(t('ui.adjustments_link')) ?></a><?php endif; ?>
        <?php if (!$locked && $recalculable && can('billing.calculate') && !$current): ?>
            <form method="post" action="<?= e(base_url('/periods/' . $c['billing_period_id'] . '/billing/calculate')) ?>" class="inline-form js-confirm"
                  data-confirm-title="<?= e(t('billing_show.recalc')) ?>" data-confirm-message="<?= e(t('billing_show.recalc_message')) ?>" data-confirm-button="<?= e(t('billing_show.recalc')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="apartment_id" value="<?= (int) $c['apartment_id'] ?>">
                <input type="hidden" name="back" value="charge">
                <button type="submit" class="btn btn--primary"><?= e(t('billing_show.recalc')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (!$locked && $current && $c['status'] === 'da_tinh' && can('billing.confirm') && $d['j16_blocking'] !== null): ?>
            <button type="button" class="btn btn--primary" disabled aria-describedby="j16Warning" title="<?= e(config('j16.gate_message')) ?>"><?= e(t('common.confirm')) ?></button>
        <?php endif; ?>
        <?php if (!$locked && $current && $c['status'] === 'da_tinh' && can('billing.confirm') && $d['j16_blocking'] === null): ?>
            <form method="post" action="<?= e(base_url('/periods/' . $c['billing_period_id'] . '/billing/confirm')) ?>" class="inline-form js-confirm"
                  data-confirm-title="<?= e(t('billing_show.confirm_title')) ?>" data-confirm-message="<?= e(t('billing_show.confirm_message', ['apartment' => $c['apartment_code'], 'amount' => money_vnd($c['total_due'])])) ?>" data-confirm-button="<?= e(t('common.confirm')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="charge_ids[]" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="token_<?= (int) $c['id'] ?>" value="<?= e($c['version_token']) ?>">
                <input type="hidden" name="back" value="charge">
                <button type="submit" class="btn btn--primary"><?= e(t('common.confirm')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (!$locked && $c['status'] === 'da_tinh' && can('billing.confirm')): ?>
            <button type="button" class="btn btn--danger" data-open-modal="returnModal"><?= e(t('common.actions.return')) ?></button>
        <?php endif; ?>
        <?php if (!$locked && $current && $c['status'] === 'da_xac_nhan' && can('billing.approve')): ?>
            <form method="post" action="<?= e(base_url('/periods/' . $c['billing_period_id'] . '/billing/approve')) ?>" class="inline-form js-confirm"
                  data-confirm-title="<?= e(t('billing_show.approve_title')) ?>" data-confirm-message="<?= e(t('billing_show.approve_message', ['apartment' => $c['apartment_code']])) ?>" data-confirm-button="<?= e(t('common.actions.approve')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="charge_ids[]" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="token_<?= (int) $c['id'] ?>" value="<?= e($c['version_token']) ?>">
                <input type="hidden" name="back" value="charge">
                <button type="submit" class="btn btn--primary"><?= e(t('billing_show.approve_title')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (!$locked && $recalculable && can('billing.update_debt')): ?>
            <button type="button" class="btn btn--ghost" data-open-modal="debtModal"><?= e(t('common.fields.old_debt')) ?></button>
        <?php endif; ?>
        <?php if (can('bills.view') && in_array($c['status'], config('billing.lockable_statuses'), true) && in_array($state, ['current', 'locked'], true)): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('/charges/' . $c['id'] . '/notice')) ?>"><?= e(t('billing_show.notice')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($state === 'stale' || $state === 'stale_error'): ?>
    <div class="alert alert--error">
        <?= t('billing_show.stale_html') ?>
        <ul><?php foreach ($d['consistency']['reasons'] as $r): ?><li><?= e(message_t($r)) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<?php if ($c['status'] === 'bi_tra_lai'): ?>
    <div class="alert alert--error"><strong><?= e(t('billing_show.returned')) ?></strong> <?= e($c['rejection_reason'] ?? '') ?> — <?= e(t('billing_show.returned_hint')) ?></div>
<?php endif; ?>
<?php if ($d['j16_blocking'] !== null): ?>
    <div class="alert alert--error" id="j16Warning" role="status">
        <strong><?= e(label_t('j16.label', (string) config('j16.label'))) ?></strong> <span class="badge badge--warning"><?= e(t('billing_show.bql_verifying')) ?></span>
        <div><?= e(label_t('j16.billing_warning', (string) config('j16.billing_warning'))) ?> <?= htmlspecialchars(t('billing_show.j16_no_fix'), ENT_NOQUOTES, 'UTF-8') ?></div>
        <?php if (can('alerts.view')): ?><a class="btn btn--ghost btn--sm" href="<?= e(base_url('/alerts/' . $d['j16_blocking']['id'])) ?>"><?= e(t('billing_show.view_alert')) ?></a><?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($d['open_alerts'] > 0): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= e(t('billing_show.open_alerts', ['n' => (int) $d['open_alerts']])) ?></div>
<?php endif; ?>
<?php foreach ($d['snapshot']['warnings'] ?? [] as $w): ?>
    <div class="notice-box" style="margin-bottom:16px"><?= e(message_t($w)) ?></div>
<?php endforeach; ?>

<?php foreach (['dien' => 'Điện', 'nuoc' => 'Nước'] as $utility => $utilityLabel): ?>
    <?php $meters = array_filter($d['meters'], static fn (array $m): bool => $m['reading']['utility_type'] === $utility); ?>
    <div class="panel">
        <div class="panel__head"><h2 class="panel__title"><?= e($utility === 'dien' ? t('billing_show.title_dien') : t('billing_show.title_nuoc')) ?></h2></div>
        <?php if ($meters === []): ?>
            <div class="empty-state"><p><?= e($utility === 'dien' ? t('billing_show.empty_dien') : t('billing_show.empty_nuoc')) ?></p></div>
        <?php endif; ?>
        <?php foreach ($meters as $m): $r = $m['reading']; $s = $snapshotMeters[(int) $r['meter_reading_id']] ?? null; ?>
            <h3 class="section-title"><?= e(t('billing_show.meter')) ?> <?= e($r['meter_code']) ?><?= $r['display_label'] ? ' (' . e($r['display_label']) . ')' : '' ?> — <?= e(label_of('usage_type', $r['usage_type'])) ?>,
                <?= e(t('billing_show.reading')) ?> <a href="<?= e(base_url('/readings/' . $r['meter_reading_id'])) ?>">#<?= (int) $r['meter_reading_id'] ?></a></h3>
            <p class="form-hint"><?= e(t('common.fields.prev_reading')) ?> <?= e(format_decimal($r['previous_reading'])) ?> → <?= e(t('common.fields.curr_reading')) ?> <?= e(format_decimal($r['current_reading'])) ?> · <?= e(t('common.fields.consumption')) ?> = <strong><?= e(format_decimal($r['reading_consumption'])) ?> <?= e($units[$utility]) ?></strong>
                <?php if ($s !== null): ?> · <?= e(t('billing_show.tariff')) ?> <a href="<?= e(base_url('/tariffs/' . $s['tariff_id'])) ?>">#<?= (int) $s['tariff_id'] ?></a> <?= e(t('billing_show.effective')) ?> <?= e(format_date($s['tariff_effective_date'])) ?> (<?= e($s['mode'] === 'flat' ? t('billing_show.flat') : t('billing_show.tiered')) ?>)<?php endif; ?></p>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th><?= e(t('common.fields.tier')) ?></th><th><?= e(t('common.fields.range')) ?></th><th class="num"><?= e(t('billing_show.quantity')) ?></th><th class="num"><?= e(t('common.fields.unit_price')) ?></th><th class="num"><?= e(t('common.fields.amount')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($m['lines'] as $l): ?>
                        <tr>
                            <td><?= (int) $l['tier_order'] ?></td>
                            <td><?= e(tier_range($l['tier_from'], $l['tier_to'], $units[$utility])) ?></td>
                            <td class="num"><?= e(format_decimal($l['quantity'])) ?></td>
                            <td class="num"><?= e(money_vnd($l['unit_price'])) ?></td>
                            <td class="num"><?= e(money_vnd($l['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><th colspan="4"><?= e(t('billing_show.subtotal')) ?></th><th class="num"><?= e(money_vnd($m['subtotal'], true)) ?></th></tr>
                        <?php if ($s !== null): ?>
                            <tr><th colspan="4">VAT <?= $s['vat_percent'] === null ? t('billing_show.vat_not_split_html') : e(format_decimal($s['vat_percent'])) . '%' ?></th><th class="num"><?= e(money_vnd($s['vat'])) ?></th></tr>
                            <tr><th colspan="4"><?= e(t('billing_show.meter_total')) ?></th><th class="num"><strong><?= e(money_vnd($s['total'])) ?></strong></th></tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        <?php endforeach; ?>
        <dl class="detail-grid">
            <?php if ($utility === 'dien'): ?>
                <div><dt><?= e(t('common.fields.consumption_elec')) ?></dt><dd><?= e(format_decimal($c['electricity_consumption'])) ?> kWh</dd></div>
                <div><dt><?= e(t('billing_show.elec_before_vat')) ?></dt><dd><?= e(money_vnd($c['electricity_amount'])) ?></dd></div>
                <div><dt><?= e(t('billing_show.elec_vat')) ?></dt><dd><?= e(money_vnd($c['electricity_vat_amount'])) ?></dd></div>
                <div><dt><?= e(t('billing_show.elec_total')) ?></dt><dd class="price-big"><?= e(money_vnd($d['columns']['electricity_amount'] + $d['columns']['electricity_vat_amount'], true)) ?></dd></div>
            <?php else: ?>
                <div><dt><?= e(t('common.fields.consumption_water')) ?></dt><dd><?= e(format_decimal($c['water_consumption'])) ?> m³</dd></div>
                <div><dt><?= e(t('billing_show.water_amount')) ?></dt><dd class="price-big"><?= e(money_vnd($c['water_amount'])) ?></dd></div>
                <div><dt><?= e(t('billing_show.water_vat')) ?></dt><dd><?= t('billing_show.not_split_html') ?></dd></div>
            <?php endif; ?>
        </dl>
    </div>
<?php endforeach; ?>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('billing_show.fixed_fees')) ?></h2></div>
    <?php if ($d['fees'] === null): ?>
        <p class="form-hint"><?= t('billing_show.no_snapshot_html') ?></p>
        <dl class="detail-grid">
            <div><dt><?= e(t('billing_show.service_fee')) ?></dt><dd><?= e(money_vnd($c['service_fee_amount'])) ?></dd></div>
            <div><dt>BHCN</dt><dd><?= e(money_vnd($c['fire_insurance_amount'])) ?></dd></div>
            <div><dt><?= e(t('billing_show.parking')) ?></dt><dd><?= e(money_vnd($c['parking_fee_amount'])) ?></dd></div>
        </dl>
    <?php else: ?>
        <?php if ($d['fee_check'] === false): ?>
            <div class="alert alert--error"><?= e(t('billing_show.snapshot_mismatch')) ?></div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('billing_show.item')) ?></th><th class="num"><?= e(t('billing_show.basis')) ?></th><th class="num"><?= e(t('common.fields.unit_price')) ?></th><th><?= e(t('billing_show.rate_version')) ?></th><th class="num"><?= e(t('common.fields.amount')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($d['fees'] as $f): ?>
                    <tr>
                        <td><?= e($feeTypes[$f['fee_type']]['label'] ?? $f['fee_type']) ?></td>
                        <td class="num"><?= e(format_decimal($f['base_quantity'])) ?> <?= e($f['base_unit']) ?></td>
                        <td class="num"><?= e(money_vnd($f['unit_price'])) ?>/<?= e($f['base_unit']) ?></td>
                        <td>#<?= (int) $f['rate_id'] ?> <?= e(t('billing_show.from')) ?> <?= e(format_date($f['rate_effective_date'])) ?></td>
                        <td class="num"><?= e(money_vnd($f['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($d['fees'] === []): ?><tr><td colspan="5" class="row-muted"><?= e(t('billing_show.no_fixed_fees')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php foreach ($d['snapshot']['not_calculated'] ?? [['fee_type' => 'mo_lai_dich_vu', 'note' => config('billing.reconnection_fee.note')]] as $n): ?>
        <p class="form-hint"><strong><?= e($feeTypes[$n['fee_type']]['label'] ?? $n['fee_type']) ?>:</strong> <?= e($n['note']) ?></p>
    <?php endforeach; ?>
</div>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('billing_show.summary')) ?></h2></div>
    <dl class="detail-grid">
        <div><dt><?= e(t('billing_show.utility_charge')) ?></dt><dd><?= e(money_vnd($d['totals']['utility_charge'], true)) ?></dd></div>
        <div><dt><?= e(t('billing_show.fixed_fee')) ?></dt><dd><?= e(money_vnd($d['totals']['fixed_fee'], true)) ?></dd></div>
        <div><dt><?= e(t('billing_show.period_total')) ?></dt><dd class="price-big"><?= e(money_vnd($d['totals']['current_period_total'], true)) ?></dd></div>
        <div><dt><?= e(t('common.fields.old_debt')) ?></dt><dd><?= e(money_vnd($d['totals']['old_debt'], true)) ?></dd></div>
        <div><dt><?= e(t('billing_show.total_payable')) ?></dt><dd class="price-big"><?= e(money_vnd($d['totals']['total_payable'], true)) ?></dd></div>
        <div><dt><?= e(t('billing_show.arithmetic')) ?></dt><dd><?= $d['totals']['consistent'] ? e(t('billing_show.consistent')) : '<span class="text-danger">' . e(t('billing_show.inconsistent')) . '</span>' ?></dd></div>
        <div><dt><?= e(t('billing_show.confirmation')) ?></dt><dd><?= e(($c['confirmed_by_name'] ?? '—') . ($c['confirmed_at'] ? ' · ' . $c['confirmed_at'] : '')) ?></dd></div>
        <div><dt><?= e(t('billing_show.bql_approval')) ?></dt><dd><?= e(($c['approved_by_name'] ?? '—') . ($c['approved_at'] ? ' · ' . $c['approved_at'] : '')) ?></dd></div>
    </dl>
    <?php if ($d['snapshot'] !== null): ?>
        <p class="form-hint"><?= e(t('billing_show.rules_at', ['at' => (string) $d['snapshot_at']])) ?>
            </p>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title"><?= e(t('billing_show.history')) ?></h2></div>
    <?php if ($d['history'] === []): ?>
        <div class="empty-state"><p><?= e(t('billing_show.no_history')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th><?= e(t('common.fields.time')) ?></th><th><?= e(t('common.fields.person')) ?></th><th><?= e(t('common.fields.action')) ?></th><th><?= e(t('billing_show.status_change')) ?></th><th><?= e(t('common.fields.note')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($d['history'] as $h): $old = json_decode((string) $h['old_value'], true) ?: []; $new = json_decode((string) $h['new_value'], true) ?: []; ?>
                    <tr>
                        <td><?= e($h['created_at']) ?></td>
                        <td><?= e($h['user_name'] ?? t('alert_show.system')) ?></td>
                        <td><?= e($h['action']) ?></td>
                        <td><?= e(($old['status'] ?? '—') . ' → ' . ($new['status'] ?? '—')) ?></td>
                        <td><?= e(implode('; ', array_filter([
                            $new['rejection_reason'] ?? null,
                            $new['reason'] ?? null,
                            isset($new['reasons']) ? implode('; ', (array) $new['reasons']) : null,
                            isset($new['snapshot']['totals']['total_payable']) ? 'Tổng phải thu ' . $new['snapshot']['totals']['total_payable'] : null,
                        ]))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (!$locked && $c['status'] === 'da_tinh' && can('billing.confirm')): ?>
<div class="modal" id="returnModal" role="dialog" aria-modal="true" aria-labelledby="returnModalTitle" <?= has_error($form, 'rejection_reason') ? '' : 'hidden' ?>>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog">
        <h2 class="modal__title" id="returnModalTitle"><?= e(t('billing_show.return_title', ['apartment' => $c['apartment_code']])) ?></h2>
        <form method="post" action="<?= e(base_url('/charges/' . $c['id'] . '/return')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="version_token" value="<?= e($c['version_token']) ?>">
            <div class="form-group <?= has_error($form, 'rejection_reason') ? 'has-error' : '' ?>">
                <label for="rejection_reason"><?= e(t('billing_show.return_reason')) ?> <span class="required">*</span></label>
                <textarea id="rejection_reason" name="rejection_reason" rows="4" maxlength="500" required><?= e(form_value($form, null, 'rejection_reason')) ?></textarea>
                <div class="form-hint"><?= e(t('billing_show.return_hint')) ?></div>
                <?= field_error($form, 'rejection_reason') ?>
            </div>
            <div class="modal__actions">
                <button type="button" class="btn btn--secondary" data-modal-close><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn--danger"><?= e(t('common.actions.return')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$locked && $recalculable && can('billing.update_debt')): ?>
<div class="modal" id="debtModal" role="dialog" aria-modal="true" aria-labelledby="debtModalTitle" <?= has_error($form, 'old_debt_amount') || has_error($form, 'reason') ? '' : 'hidden' ?>>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog">
        <h2 class="modal__title" id="debtModalTitle"><?= e(t('billing_show.debt_title', ['apartment' => $c['apartment_code']])) ?></h2>
        <form method="post" action="<?= e(base_url('/charges/' . $c['id'] . '/old-debt')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="version_token" value="<?= e($c['version_token']) ?>">
            <div class="form-group <?= has_error($form, 'old_debt_amount') ? 'has-error' : '' ?>">
                <label for="old_debt_amount"><?= e(t('billing_show.debt_amount')) ?> <span class="required">*</span></label>
                <input id="old_debt_amount" name="old_debt_amount" inputmode="decimal" value="<?= e(form_value($form, ['old_debt_amount' => format_input($c['old_debt_amount'])], 'old_debt_amount')) ?>">
                <?= field_error($form, 'old_debt_amount') ?>
            </div>
            <div class="form-group <?= has_error($form, 'reason') ? 'has-error' : '' ?>">
                <label for="reason"><?= e(t('billing_show.debt_reason')) ?> <span class="required">*</span></label>
                <textarea id="reason" name="reason" rows="3" maxlength="500"><?= e(form_value($form, null, 'reason')) ?></textarea>
                <?= field_error($form, 'reason') ?>
            </div>
            <p class="form-hint"><?= t('billing_show.debt_hint_html') ?></p>
            <div class="modal__actions">
                <button type="button" class="btn btn--secondary" data-modal-close><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn--primary"><?= e(t('billing_show.save_debt')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
