<?php
/** @var array $filters @var ?array $pager @var array $errors */
$breadcrumbs = [[t('crumbs.reports'), null], [t('page.outbox'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="form-error">' . e($errors[$k][0]) . '</div>' : '';
$fullContact = can('residents.view_phone');
$statuses = ['pending' => t('lit.sending'), 'sent' => t('lit.sent'), 'failed' => t('lit.failed')];
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.outbox')) ?></h1>
        <p class="page-subtitle"><?= t('ui.fn_10_03_secondary_history_of') ?> <strong><?= e(config('mail.driver') === 'smtp' ? t('lit.smtp_real_sending') : 'demo "' . config('mail.driver') . t('lit.nothing_is_sent_to_the_internet')) ?></strong>.</p>
    </div>
</div>
<div class="panel">
    <form method="get" action="<?= e(base_url('/email-notifications')) ?>" class="toolbar">
        <div class="form-group <?= isset($errors['status']) ? 'has-error' : '' ?>"><label for="status"><?= e(t('common.fields.status')) ?></label><select id="status" name="status"><option value=""><?= e(t('common.options.all')) ?></option><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $err('status') ?></div>
        <div class="form-group"><label for="recipient"><?= e(t('common.fields.recipient')) ?></label><input id="recipient" name="recipient" maxlength="100" value="<?= e($filters['recipient']) ?>" placeholder="<?= e(t('common.placeholders.name_or_email')) ?>"></div>
        <div class="form-group"><label for="apartment"><?= e(t('common.fields.apartment')) ?></label><input id="apartment" name="apartment" maxlength="30" value="<?= e($filters['apartment']) ?>" placeholder="VD: BT1"></div>
        <div class="form-group <?= isset($errors['date_from']) ? 'has-error' : '' ?>"><label for="date_from"><?= e(t('common.fields.from_date')) ?></label><input id="date_from" name="date_from" type="date" value="<?= e($filters['date_from']) ?>"><?= $err('date_from') ?></div>
        <div class="form-group <?= isset($errors['date_to']) ? 'has-error' : '' ?>"><label for="date_to"><?= e(t('common.fields.to_date')) ?></label><input id="date_to" name="date_to" type="date" value="<?= e($filters['date_to']) ?>"><?= $err('date_to') ?></div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('common.actions.filter')) ?></button>
        <a class="btn btn--secondary btn--sm" href="<?= e(base_url('/email-notifications')) ?>"><?= e(t('common.actions.clear_filters')) ?></a>
    </form>
    <?php if ($pager === null): ?>
        <div class="form-summary"><?= t('ui.invalid_filter_see_the_error_on') ?></div>
    <?php elseif ($pager['data'] === []): ?>
        <div class="empty-state"><p><?= e(t('landing.outbox.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>#</th><th><?= e(t('common.fields.recipient')) ?></th><th><?= e(t('common.fields.apartment')) ?></th><th><?= e(t('common.fields.period')) ?></th><th><?= t('ui.subject') ?></th><th><?= e(t('common.fields.status')) ?></th><th class="num"><?= t('ui.attempts') ?></th><th><?= e(t('common.fields.sent_at')) ?></th><th><?= e(t('common.fields.error')) ?></th><th><?= e(t('common.fields.created_at')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($pager['data'] as $n): ?>
                <tr>
                    <td><a href="<?= e(base_url('/email-notifications/' . $n['id'])) ?>">#<?= (int) $n['id'] ?></a></td>
                    <td><?= e(($n['recipient_name'] ?? '') . ' — ' . ($fullContact ? $n['recipient_email'] : mask_email($n['recipient_email']))) ?></td>
                    <td><?= e($n['building_code'] . ' / ' . $n['apartment_code']) ?></td>
                    <td><?= e($n['period_code'] ?? '—') ?></td>
                    <td class="cell-wrap"><?= e($n['subject']) ?></td>
                    <td><?= email_status_badge($n['status']) ?><?= $n['transport'] !== 'smtp' ? ' <span class="tag-note">' . e($n['transport']) . '</span>' : '' ?></td>
                    <td class="num"><?= (int) $n['attempt_count'] ?></td>
                    <td><?= e($n['sent_at'] ?? '—') ?></td>
                    <td class="cell-wrap"><?= e(message_t($n['last_error'] ?? '')) ?></td>
                    <td><?= e($n['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?php if ($pager !== null): $basePath = '/email-notifications'; require BASE_PATH . '/resources/views/partials/pagination.php'; endif; ?>
</div>
