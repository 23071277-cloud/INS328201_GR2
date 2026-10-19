<?php
/** @var bool $available @var array $periods @var ?array $summary @var array $recent */
$breadcrumbs = [[t('crumbs.payments'), null], [t('crumbs.payment_dashboard'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$periods ??= [];
$recent ??= [];
$summary ??= null;
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.payments')) ?></h1>
        <p class="page-subtitle"><?= e(t('landing.payments.subtitle')) ?></p>
    </div>
    <?php if ($available): ?>
    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(base_url('/payments/review')) ?>"><?= t('ui.transactions_needing_review') ?><?= (int) ($summary['counts']['unmatched'] ?? 0) ?>)</a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$available): ?>
    <div class="notice-box"><?= t('ui.payments_are_not_enabled_on_this') ?> <code>payment_transactions</code> <?= t('ui.is_missing_an_approved_migration_is') ?></div>
<?php elseif ($periods === []): ?>
    <div class="panel"><div class="empty-state"><p><?= e(t('landing.payments.empty_periods')) ?></p></div></div>
<?php else: ?>
    <div class="panel">
        <form method="get" action="<?= e(base_url('/payments')) ?>" class="toolbar">
            <div class="form-group"><label for="period_id"><?= e(t('common.fields.period')) ?></label>
                <select id="period_id" name="period_id">
                    <?php foreach ($periods as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($summary['period']['id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['period_code']) ?><?= $p['status'] === 'mo_lai' ? t('lit.reopened') : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn--primary btn--sm">Xem</button>
        </form>
        <?php if ($summary !== null && !$summary['payable']): ?>
            <div class="notice-box"><?= t('common.fields.period') ?> <?= e($summary['period']['period_code']) ?> <?= t('ui.is') ?> <strong><?= t('ui.reopened') ?></strong><?= t('ui.no_new_payments_are_accepted_new') ?> <strong><?= t('menu.payment_review') ?></strong>.</div>
        <?php endif; ?>
    </div>

    <?php if ($summary !== null): $t = $summary['totals']; $c = $summary['counts']; ?>
    <div class="card-grid" data-payment-kpi>
        <div class="stat-card"><div class="stat-card__label"><?= t('ui.total_due') ?><?= (int) $c['charges'] ?> <?= t('ui.apartments') ?></div><div class="stat-card__value" data-kpi="due"><?= e(money_vnd($t['due'])) ?></div></div>
        <div class="stat-card"><div class="stat-card__label"><?= t('ui.collected') ?><?= (int) $c['PAID'] ?> <?= t('ui.apartments') ?></div><div class="stat-card__value" data-kpi="collected" style="color:var(--c-success)"><?= e(money_vnd($t['collected'])) ?></div></div>
        <div class="stat-card"><div class="stat-card__label"><?= t('ui.not_collected') ?><?= (int) $c['UNPAID'] ?> <?= t('ui.apartments') ?></div><div class="stat-card__value" data-kpi="outstanding"><?= e(money_vnd($t['outstanding'])) ?></div></div>
        <div class="stat-card"><div class="stat-card__label"><?= t('ui.needs_review') ?><?= (int) $c['PAYMENT_REVIEW'] ?> <?= t('ui.apartments_2') ?> <?= (int) $c['unmatched'] ?> <?= t('ui.transactions') ?></div><div class="stat-card__value" data-kpi="review" style="color:var(--c-danger)"><?= e(money_vnd($t['review_amount'])) ?></div></div>
    </div>

    <div class="panel">
        <div class="panel__head"><h2 class="panel__title"><?= t('ui.payment_status_by_apartment_period') ?> <?= e($summary['period']['period_code']) ?></h2></div>
        <?php if ($summary['rows'] === []): ?>
            <div class="empty-state"><p><?= e(t('landing.payments.empty_charges')) ?></p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th><?= e(t('common.fields.apartment')) ?></th><th><?= t('ui.payment_reference') ?></th><th class="num"><?= e(t('common.fields.amount_due')) ?></th><th class="num"><?= t('ui.recorded') ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= t('ui.paid_at') ?></th><th class="actions"></th></tr></thead>
            <tbody>
            <?php foreach ($summary['rows'] as $r): ?>
                <tr data-payment-row="<?= e($r['ref']) ?>">
                    <td><?= e($r['building_code'] . ' / ' . $r['apartment_code']) ?></td>
                    <td><code><?= e($r['ref']) ?></code></td>
                    <td class="num"><?= e(money_vnd($r['total_due'])) ?></td>
                    <td class="num"><?= e(money_vnd(\App\Core\Decimal::fromCents($r['recorded']))) ?></td>
                    <td><?= payment_status_badge($r['status']) ?></td>
                    <td><?= e($r['last_paid_at'] ?? '—') ?></td>
                    <td class="actions"><?php if (can('bills.view')): ?><a href="<?= e(base_url('/charges/' . $r['id'] . '/notice')) ?>"><?= t('ui.notice') ?></a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel__head"><h2 class="panel__title"><?= t('ui.latest_transactions') ?></h2></div>
        <?php if ($recent === []): ?>
            <div class="empty-state"><p><?= e(t('landing.payments.empty_transactions')) ?></p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th><?= t('ui.time') ?></th><th><?= t('ui.transaction_id') ?></th><th class="num"><?= t('ui.amount') ?></th><th><?= t('ui.transfer_description') ?></th><th><?= t('ui.apartment_period') ?></th><th><?= t('ui.reconciliation_result') ?></th></tr></thead>
            <tbody>
            <?php foreach ($recent as $x): ?>
                <tr>
                    <td><?= e($x['received_at']) ?></td>
                    <td><code><?= e($x['transaction_id']) ?></code></td>
                    <td class="num"><?= e(money_vnd($x['amount'])) ?></td>
                    <td class="cell-wrap"><?= e($x['transfer_content']) ?></td>
                    <td><?= $x['apartment_code'] !== null ? e($x['building_code'] . ' / ' . $x['apartment_code'] . ' · ' . $x['period_code']) : '—' ?></td>
                    <td><?= payment_match_badge($x['match_status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
