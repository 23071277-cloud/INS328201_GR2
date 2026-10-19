<?php
/** @var array $n */
$breadcrumbs = [[t('menu.groups.reports'), null], [t('page.outbox'), '/email-notifications'], ['#' . $n['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$fullContact = can('residents.view_phone');
$stale = $n['status'] === 'pending' && strtotime((string) $n['updated_at']) < time() - \App\Services\EmailNotificationService::STALE_PENDING_MINUTES * 60;
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Email #<?= (int) $n['id'] ?></h1>
        <p class="page-subtitle"><?= email_status_badge($n['status']) ?> <?= e($n['subject']) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($n['apartment_charge_id'] !== null && can('bills.view')): ?><a class="btn btn--ghost" href="<?= e(base_url('/charges/' . $n['apartment_charge_id'] . '/notice')) ?>"><?= t('ui.view_notice') ?></a><?php endif; ?>
        <?php if (can('email_notifications.send') && ($n['status'] === 'failed' || $stale)): ?>
            <form method="post" action="<?= e(base_url('/email-notifications/' . $n['id'] . '/retry')) ?>" class="inline-form js-confirm" data-confirm-title="<?= t('ui.resend_email') ?>" data-confirm-message="<?= t('ui.resend_this_notice_to_the_recipient') ?>" data-confirm-button="<?= t('common.actions.resend') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary"><?= e(t('common.actions.resend')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('common.fields.recipient') ?></dt><dd><?= e(($n['recipient_name'] ?? '') . ' — ' . ($fullContact ? $n['recipient_email'] : mask_email($n['recipient_email']))) ?></dd></div>
        <div><dt><?= t('ui.apartment_period_2') ?></dt><dd><?= e($n['building_code'] . ' / ' . $n['apartment_code'] . ' · ' . ($n['period_code'] ?? '—')) ?></dd></div>
        <div><dt><?= t('common.fields.status') ?></dt><dd><?= email_status_badge($n['status']) ?> · <?= (int) $n['attempt_count'] ?> <?= t('ui.attempts_2') ?></dd></div>
        <div><dt><?= t('ui.sending_mode') ?></dt><dd><?= e($n['transport'] === 'smtp' ? t('lit.smtp_real_sending') : 'Demo "' . $n['transport'] . t('lit.nothing_is_sent_to_the_internet')) ?></dd></div>
        <div><dt><?= t('common.fields.sent_at') ?></dt><dd><?= e($n['sent_at'] ?? '—') ?></dd></div>
        <div><dt><?= t('ui.created_by_at') ?></dt><dd><?= e(($n['created_by_name'] ?? '—') . ' · ' . $n['created_at']) ?></dd></div>
    </dl>
    <?php if ($n['last_error']): ?><div class="form-summary" style="margin-top:16px"><?= t('ui.latest_sending_error') ?> <?= e(message_t($n['last_error'])) ?></div><?php endif; ?>
</div>
<div class="panel">
    <h2 class="panel__title"><?= t('ui.sent_content_stored_copy') ?></h2>
    <?php /* ảnh QR nhúng (cid:) chỉ hiển thị được trong hộp thư - khi xem lại ở đây thay bằng ảnh tĩnh cùng nội dung; bản lưu không đổi */
    $preview = (string) $n['body_html'];
    foreach ((array) config('email_images', []) as $cid => $file) {
        $preview = str_replace('cid:' . $cid, base_url('/' . preg_replace('#^public/#', '', (string) $file)), $preview);
    } ?>
    <iframe title="<?= e(t('lit.email_content_title')) ?>" sandbox srcdoc="<?= e($preview) ?>" style="width:100%;height:760px;border:1px solid var(--c-border);border-radius:8px;background:#fff"></iframe>
    <details style="margin-top:12px"><summary><?= t('ui.plain_text_version') ?></summary><pre class="code-block"><?= e($n['body_text']) ?></pre></details>
</div>
