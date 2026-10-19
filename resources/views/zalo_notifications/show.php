<?php
/** @var array $n */
$breadcrumbs = [[t('menu.groups.reports'), null], [t('zalo.title'), '/zalo-notifications'], ['#' . $n['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$phone = can('residents.view_phone') ? '0' . substr((string) $n['recipient_phone'], 2) : \App\Services\ZaloClient::maskPhone((string) $n['recipient_phone']);
$stale = $n['status'] === 'pending' && strtotime((string) $n['updated_at']) < time() - \App\Services\ZaloNotificationService::STALE_PENDING_MINUTES * 60;
$params = (array) json_decode((string) $n['template_data'], true);
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('zalo.msg_title', ['id' => (int) $n['id']])) ?></h1>
        <p class="page-subtitle"><?= email_status_badge($n['status']) ?> <?= e(t('zalo.notice_line', ['apt' => $n['building_code'] . ' / ' . $n['apartment_code'], 'period' => $n['period_code'] ?? '—'])) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($n['apartment_charge_id'] !== null && can('bills.view')): ?><a class="btn btn--ghost" href="<?= e(base_url('/charges/' . $n['apartment_charge_id'] . '/notice')) ?>"><?= e(t('zalo.view_notice')) ?></a><?php endif; ?>
        <?php if (can('zalo_notifications.send') && ($n['status'] === 'failed' || $stale)): ?>
            <form method="post" action="<?= e(base_url('/zalo-notifications/' . $n['id'] . '/retry')) ?>" class="inline-form js-confirm" data-confirm-title="<?= e(t('zalo.resend_title')) ?>" data-confirm-message="<?= e(t('zalo.resend_msg')) ?>" data-confirm-button="<?= e(t('zalo.resend')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary"><?= e(t('zalo.resend')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= e(t('zalo.recipient')) ?></dt><dd><?= e(($n['recipient_name'] ?? '') . ' — ' . $phone) ?></dd></div>
        <div><dt><?= e(t('zalo.status')) ?></dt><dd><?= email_status_badge($n['status']) ?> · <?= e(t('zalo.attempts_n', ['n' => (int) $n['attempt_count']])) ?></dd></div>
        <div><dt><?= e(t('zalo.mode_label')) ?></dt><dd><?= e($n['transport'] === 'zns' ? t('zalo.mode_real') : t('zalo.mode_demo', ['mode' => $n['transport']])) ?></dd></div>
        <div><dt><?= e(t('zalo.sent_at')) ?></dt><dd><?= e($n['sent_at'] ?? '—') ?></dd></div>
        <div><dt><?= e(t('zalo.msg_id')) ?></dt><dd><?= e($n['zalo_msg_id'] ?? '—') ?></dd></div>
        <div><dt><?= e(t('zalo.tracking')) ?></dt><dd><?= e($n['tracking_id']) ?></dd></div>
        <div><dt><?= e(t('zalo.template')) ?></dt><dd><?= e($n['template_id']) ?></dd></div>
        <div><dt><?= e(t('zalo.created_by')) ?></dt><dd><?= e(($n['created_by_name'] ?? '—') . ' · ' . $n['created_at']) ?></dd></div>
    </dl>
    <?php if ($n['last_error']): ?><div class="form-summary" style="margin-top:16px"><?= e(t('zalo.last_error')) ?> <?= e($n['last_error']) ?></div><?php endif; ?>
</div>
<div class="panel">
    <h2 class="panel__title"><?= e(t('zalo.body_title')) ?></h2>
    <p><?= e($n['body_text']) ?></p>
    <?php if ($params !== []): ?>
        <details style="margin-top:12px"><summary><?= e(t('zalo.params')) ?></summary>
            <table class="data-table"><tbody><?php foreach ($params as $k => $v): ?><tr><td><code><?= e((string) $k) ?></code></td><td><?= e((string) $v) ?></td></tr><?php endforeach; ?></tbody></table>
        </details>
    <?php endif; ?>
</div>
