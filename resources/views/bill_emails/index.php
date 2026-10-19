<?php
/** @var array $r từ PeriodBillEmailService::report */
$p = $r['period'];
$c = $r['count'];
$breadcrumbs = [[t('menu.groups.readings'), null], [t('menu.periods'), '/periods'], [$p['period_code'], '/periods/' . $p['id']], [t('period_show.period_outbox'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$locked = $p['status'] === 'da_khoa';
$showEmail = can('residents.view_phone'); // BR-36: chỉ BQL thấy email đầy đủ
$stateLabel = ['sent' => [t('lit.sent'), 'badge--active'], 'failed' => [t('lit.failed'), 'badge--danger'], 'missing' => [t('period_show.missing_email'), 'badge--warning'], 'pending' => [t('period_show.not_sent'), 'badge--muted']];
$nLabel = ['pending' => t('lit.sending'), 'sent' => t('lit.sent'), 'failed' => t('lit.failed')];
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.period_outbox_fee_notices_for_period') ?> <?= e($p['period_code']) ?></h1>
        <p class="page-subtitle"><?= t('ui.fn_10_03_gmail_is_sent') ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($locked && can('email_notifications.send')): ?>
            <form method="post" action="<?= e(base_url('/periods/' . $p['id'] . '/bill-emails/send')) ?>" class="inline-form js-confirm" data-confirm-title="<?= t('period_show.gmail_send') ?>" data-confirm-message="<?= t('ui.send_fee_notices_to_apartments_recipients') ?>" data-confirm-button="<?= t('alert_j16.send') ?>">
                <?= csrf_field() ?><button type="submit" class="btn btn--ghost"><?= t('period_show.gmail_send') ?></button>
            </form>
            <?php if ($c['failed'] > 0): ?>
                <form method="post" action="<?= e(base_url('/periods/' . $p['id'] . '/bill-emails/retry-failed')) ?>" class="inline-form">
                    <?= csrf_field() ?><button type="submit" class="btn btn--primary"><?= t('ui.resend_failed_emails') ?><?= (int) $c['failed'] ?>)</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (($zalo ?? null) !== null && $locked && can('zalo_notifications.send')): ?>
            <form method="post" action="<?= e(base_url('/periods/' . $p['id'] . '/bill-zalo/send')) ?>" class="inline-form js-confirm" data-confirm-title="<?= e(t('zalo.send_to_all')) ?>" data-confirm-message="<?= e(t('zalo.confirm_period_msg', ['period' => $p['period_code']])) ?>" data-confirm-button="<?= e(t('zalo.confirm_btn')) ?>">
                <?= csrf_field() ?><button type="submit" class="btn btn--zalo"><?= e(t('zalo.send_to_all')) ?></button>
            </form>
            <?php if ($zalo['failed'] > 0): ?>
                <form method="post" action="<?= e(base_url('/periods/' . $p['id'] . '/bill-zalo/retry-failed')) ?>" class="inline-form">
                    <?= csrf_field() ?><button type="submit" class="btn btn--danger"><?= e(t('zalo.resend_failed')) ?> (<?= (int) $zalo['failed'] ?>)</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <a class="btn btn--ghost" href="<?= e(base_url('/periods/' . $p['id'])) ?>"><?= t('ui.back_to_period') ?></a>
    </div>
</div>

<?php if (!$locked): ?><div class="notice-box" style="margin-bottom:16px"><?= t('ui.period_not_locked_fee_notices_are') ?></div><?php endif; ?>

<div class="card-grid" data-bill-email-summary>
    <div class="stat-card"><div class="stat-card__label"><?= t('ui.total_apartments_confirmed_bills') ?></div><div class="stat-card__value" data-count="total"><?= (int) $c['total'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= t('ui.sent') ?></div><div class="stat-card__value" data-count="sent"><?= (int) $c['sent'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= t('ui.failed_awaiting_retry') ?></div><div class="stat-card__value" data-count="failed"><?= (int) $c['failed'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= t('period_show.missing_email') ?></div><div class="stat-card__value" data-count="missing"><?= (int) $c['missing'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= t('period_show.not_sent') ?></div><div class="stat-card__value" data-count="pending"><?= (int) $c['pending'] ?></div></div>
</div>

<?php if (($zalo ?? null) !== null): ?>
<h2 class="section-title"><?= e(t('zalo.section')) ?></h2>
<div class="card-grid" data-bill-zalo-summary>
    <div class="stat-card"><div class="stat-card__label"><?= e(t('zalo.all_sent')) ?></div><div class="stat-card__value"><?= (int) $zalo['sent'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= e(t('zalo.failed_wait')) ?></div><div class="stat-card__value"><?= (int) $zalo['failed'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= e(t('zalo.missing_phone')) ?></div><div class="stat-card__value"><?= (int) $zalo['missing'] ?></div></div>
    <div class="stat-card"><div class="stat-card__label"><?= e(t('zalo.pending')) ?></div><div class="stat-card__value"><?= (int) $zalo['pending'] ?></div></div>
    <?php if (can('zalo_notifications.view')): ?><div class="stat-card"><div class="stat-card__label"><?= e(t('zalo.details')) ?></div><div class="stat-card__value stat-card__value--link"><a href="<?= e(url_with('/zalo-notifications', ['period_id' => (string) $p['id']])) ?>"><?= e(t('zalo.title')) ?> →</a></div></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
    <?php if ($r['rows'] === []): ?>
        <div class="empty-state"><p><?= t('landing.payments.empty_charges') ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th><?= e(t('common.fields.apartment')) ?></th><th><?= e(t('common.fields.charge')) ?></th><th class="num"><?= e(t('common.fields.total_due')) ?></th><th><?= e(t('common.fields.status')) ?></th><th><?= t('ui.recipient_email') ?></th></tr></thead>
            <tbody>
            <?php foreach ($r['rows'] as $row): $ch = $row['charge']; [$sl, $sc] = $stateLabel[$row['state']]; ?>
                <tr data-bill-email-row="<?= (int) $ch['apartment_id'] ?>" data-state="<?= e($row['state']) ?>">
                    <td><?= e($ch['building_code'] . ' / ' . $ch['apartment_code']) ?></td>
                    <td><?php if (can('billing.view')): ?><a href="<?= e(base_url('/charges/' . $ch['id'])) ?>">#<?= (int) $ch['id'] ?></a><?php else: ?>#<?= (int) $ch['id'] ?><?php endif; ?> <?= charge_badge($ch['status']) ?></td>
                    <td class="num"><?= e(money_vnd($ch['total_due'])) ?></td>
                    <td><span class="badge <?= $sc ?>"><?= e($sl) ?></span></td>
                    <td>
                        <?php if ($row['notifications'] === [] && $row['recipients'] === []): ?>
                            <span class="form-hint"><?= t('ui.no_active_owner_tenant_with_an') ?></span>
                        <?php endif; ?>
                        <?php foreach ($row['notifications'] as $n): ?>
                            <div><?= e(($n['recipient_name'] ?? '') . ' — ' . ($showEmail ? $n['recipient_email'] : mask_email($n['recipient_email']))) ?> ·
                                <?php if (can('email_notifications.view')): ?><a href="<?= e(base_url('/email-notifications/' . $n['id'])) ?>"><?= e($nLabel[$n['status']] ?? $n['status']) ?></a><?php else: ?><?= e($nLabel[$n['status']] ?? $n['status']) ?><?php endif; ?>
                                <?= (int) $n['attempt_count'] > 1 ? '(' . (int) $n['attempt_count'] . t('lit.attempts') : '' ?><?= $n['status'] === 'failed' && $n['last_error'] ? ' <span class="form-hint">' . e($n['last_error']) . '</span>' : '' ?><?= $n['latest'] ? '' : t('lit.span_class_form_hint_older_attempt') ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
