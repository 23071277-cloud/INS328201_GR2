<?php
/** @var bool $available @var array $queue */
$breadcrumbs = [[t('crumbs.payments'), '/payments'], [t('page.payment_review'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$canReview = can('payments.review');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.payment_review')) ?> (<?= count($queue) ?>)</h1>
        <p class="page-subtitle"><?= t('ui.wrong_amount_unknown_payment_reference_period') ?> <strong><?= t('ui.ignore') ?></strong> <?= t('ui.with_a_reason') ?></p>
    </div>
    <div class="page-head__actions"><a class="btn btn--ghost" href="<?= e(base_url('/payments')) ?>"><?= t('crumbs.payment_dashboard') ?></a></div>
</div>

<?php if (!$available): ?>
    <div class="notice-box"><?= t('ui.payments_are_not_enabled_on_this') ?> <code>payment_transactions</code> <?= t('ui.is_missing_an_approved_migration_is') ?></div>
<?php elseif ($queue === []): ?>
    <div class="panel"><div class="empty-state"><p><strong><?= e(t('landing.payment_review.empty')) ?></strong></p></div></div>
<?php else: ?>
    <?php foreach ($queue as $x): ?>
    <div class="panel" data-review-txn="<?= e($x['transaction_id']) ?>">
        <div class="panel__head">
            <div>
                <h2 class="panel__title"><?= t('ui.transaction') ?> <code><?= e($x['transaction_id']) ?></code> — <?= e(money_vnd($x['amount'])) ?></h2>
                <p class="panel__subtitle"><?= e($x['received_at']) ?> <?= t('ui.description') ?><?= e($x['transfer_content']) ?>”<?= $x['payer_name'] ? t('lit.sender') . e($x['payer_name']) : '' ?></p>
            </div>
            <?= payment_match_badge($x['match_status']) ?>
        </div>
        <p class="form-hint"><?= e($x['match_note'] ?? '') ?><?= $x['apartment_code'] !== null ? t('lit.bill') . e($x['building_code'] . ' / ' . $x['apartment_code'] . t('lit.period') . $x['period_code'] . t('lit.amount_due') . money_vnd($x['total_due'])) . ' (' . e(\App\Services\PaymentStatusService::ref((int) $x['apartment_charge_id'])) . ').' : '' ?></p>
        <?php if ($canReview): ?>
        <div class="card-grid" style="margin-bottom:0">
            <form method="post" action="<?= e(base_url('/payments/' . $x['id'] . '/match')) ?>" class="form-stack" data-review-match>
                <?= csrf_field() ?>
                <div class="form-group"><label for="ref_<?= (int) $x['id'] ?>"><?= t('ui.match_to_payment_reference') ?></label>
                    <input id="ref_<?= (int) $x['id'] ?>" name="ref" maxlength="30" required placeholder="<?= e(\App\Services\PaymentStatusService::ref(12)) ?>" value="<?= $x['apartment_charge_id'] !== null ? e(\App\Services\PaymentStatusService::ref((int) $x['apartment_charge_id'])) : '' ?>"></div>
                <div class="form-group"><label for="mnote_<?= (int) $x['id'] ?>"><?= t('ui.reason') ?> <span class="required">*</span></label>
                    <input id="mnote_<?= (int) $x['id'] ?>" name="note" maxlength="500" required placeholder="<?= t('ui.e_g_confirmed_with_the_owner') ?>"></div>
                <button type="submit" class="btn btn--primary btn--sm"><?= t('ui.match_manually') ?></button>
            </form>
            <form method="post" action="<?= e(base_url('/payments/' . $x['id'] . '/ignore')) ?>" class="form-stack" data-review-ignore>
                <?= csrf_field() ?>
                <div class="form-group"><label for="inote_<?= (int) $x['id'] ?>"><?= t('ui.reason_for_ignoring') ?> <span class="required">*</span></label>
                    <input id="inote_<?= (int) $x['id'] ?>" name="note" maxlength="500" required placeholder="<?= t('ui.e_g_duplicate_transfer_refunded') ?>"></div>
                <button type="submit" class="btn btn--secondary btn--sm"><?= t('ui.ignore') ?></button>
            </form>
        </div>
        <?php else: ?>
            <p class="form-hint"><?= t('ui.only_accounting_handles_transactions_needing_review') ?></p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
