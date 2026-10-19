<?php
/** @var array $period @var array $rows @var string $mode confirm|approve */
$isConfirm = $mode === 'confirm';
$breadcrumbs = [[t('period_show.billing_button'), null], [t('menu.periods'), '/periods'], [$period['period_code'], '/periods/' . $period['id']], [t('billing_show.crumb_billing'), '/periods/' . $period['id'] . '/billing'], [$isConfirm ? t('lit.review_confirm') : t('billing_show.approve_title'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$locked = $period['status'] === 'da_khoa';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= $isConfirm ? t('lit.review_amp_confirm_billing_data') : t('lit.bql_bill_approval') ?> <?= t('ui.period') ?> <?= e($period['period_code']) ?></h1>
        <p class="page-subtitle">
            <?php if ($isConfirm): ?>
                <?= t('guide.review_confirm') ?>
            <?php else: ?>
                <?= t('guide.review_approve') ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="panel">
    <?php if ($locked): ?>
        <div class="notice-box"><?= t('ui.period_locked_bills_cannot_be_changed') ?></div>
    <?php elseif ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon">✅</div><p><?= t('ui.no_bills') ?> <?= $isConfirm ? t('lit.calculated') : t('period_show.stat_confirmed') ?> <?= t('ui.awaiting_action') ?></p></div>
    <?php else: ?>
        <form method="post" action="<?= e(base_url('/periods/' . $period['id'] . ($isConfirm ? '/billing/confirm' : '/billing/approve'))) ?>" class="js-confirm"
              data-confirm-title="<?= $isConfirm ? t('lit.confirm_bills') : t('lit.approve_bills') ?>"
              data-confirm-message="<?= $isConfirm ? t('lit.confirm_the_selected_bills_bills_that') : t('lit.approve_the_selected_bills') ?>"
              data-confirm-button="<?= $isConfirm ? t('common.confirm') : t('common.actions.approve') ?>">
            <?= csrf_field() ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th><input type="checkbox" data-check-all aria-label="<?= t('ui.select_all') ?>"></th><th><?= e(t('common.fields.apartment')) ?></th><th class="num"><?= e(t('common.fields.consumption_elec')) ?></th><th class="num"><?= e(t('common.fields.consumption_water')) ?></th><th class="num"><?= e(t('common.fields.total_charges')) ?></th><th class="num"><?= e(t('common.fields.old_debt')) ?></th><th class="num"><?= e(t('common.fields.total_due')) ?></th><th><?= e(t('common.fields.consistency')) ?></th><th class="actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): $c = $row['charge']; $ok = $row['state'] === 'current'; ?>
                        <tr class="<?= $ok ? '' : 'row-error' ?>">
                            <td>
                                <?php if ($ok): ?>
                                    <input type="checkbox" name="charge_ids[]" value="<?= (int) $c['id'] ?>" data-check-item>
                                    <input type="hidden" name="token_<?= (int) $c['id'] ?>" value="<?= e($c['version_token']) ?>">
                                <?php endif; ?>
                            </td>
                            <td><?= e($row['apartment']['building_code'] . ' / ' . $row['apartment']['code']) ?>
                                <?php if ($mode === 'confirm' && isset($j16Blocking[(int) $c['apartment_id']])): ?>
                                    <div><span class="badge badge--warning"><?= t('ui.j_16_under_bql_verification') ?></span> <a href="<?= e(base_url('/alerts/' . $j16Blocking[(int) $c['apartment_id']])) ?>"><?= t('billing_show.view_alert') ?></a></div>
                                    <div class="form-hint"><?= t('ui.cannot_be_confirmed_until_bql_concludes') ?></div>
                                <?php endif; ?></td>
                            <td class="num"><?= e(format_decimal($c['electricity_consumption'])) ?></td>
                            <td class="num"><?= e(format_decimal($c['water_consumption'])) ?></td>
                            <td class="num"><?= e(money_vnd($c['total_arising'])) ?></td>
                            <td class="num"><?= e(money_vnd($c['old_debt_amount'])) ?></td>
                            <td class="num"><strong><?= e(money_vnd($c['total_due'])) ?></strong></td>
                            <td><?= consistency_badge($row['state']) ?><?php foreach (array_slice($row['reasons'], 0, 2) as $r): ?><div class="form-hint"><?= e(message_t($r)) ?></div><?php endforeach; ?></td>
                            <td class="actions"><a href="<?= e(base_url('/charges/' . $c['id'])) ?>"><?= t('ui.view_details') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><?= $isConfirm ? t('lit.confirm_selected_bills') : t('lit.approve_selected_bills') ?></button></div>
        </form>
    <?php endif; ?>
</div>
