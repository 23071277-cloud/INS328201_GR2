<?php
/** @var array $filters @var ?array $pager @var array $errors @var bool $unavailable */
$breadcrumbs = [[t('crumbs.reports'), null], [t('zalo.title'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="form-error">' . e($errors[$k][0]) . '</div>' : '';
$fullContact = can('residents.view_phone');
$statuses = ['pending' => t('zalo.st_pending'), 'sent' => t('zalo.st_sent'), 'failed' => t('zalo.st_failed')];
$phoneText = static fn (string $p84): string => can('residents.view_phone') ? '0' . substr($p84, 2) : \App\Services\ZaloClient::maskPhone($p84);
$driver = (string) config('zalo.driver', 'log');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('zalo.title')) ?></h1>
        <p class="page-subtitle"><?= e(t('zalo.history_subtitle')) ?> <strong><?= e($driver === 'zns' ? t('zalo.mode_real') : t('zalo.mode_demo', ['mode' => $driver])) ?></strong>.</p>
    </div>
</div>
<?php if ($unavailable ?? false): ?>
    <div class="panel"><div class="empty-state"><p><?= e(t('zalo.unavailable')) ?> <code>database/migrations/20261018_zalo_notifications.sql</code>.</p></div></div>
<?php else: ?>
<div class="panel">
    <form method="get" action="<?= e(base_url('/zalo-notifications')) ?>" class="toolbar">
        <div class="form-group <?= isset($errors['status']) ? 'has-error' : '' ?>"><label for="status"><?= e(t('zalo.status')) ?></label><select id="status" name="status"><option value=""><?= e(t('zalo.all')) ?></option><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $err('status') ?></div>
        <div class="form-group"><label for="recipient"><?= e(t('zalo.recipient')) ?></label><input id="recipient" name="recipient" maxlength="100" value="<?= e($filters['recipient'] ?? '') ?>" placeholder="<?= e(t('zalo.ph_recipient')) ?>"></div>
        <div class="form-group"><label for="apartment"><?= e(t('zalo.apartment')) ?></label><input id="apartment" name="apartment" maxlength="30" value="<?= e($filters['apartment'] ?? '') ?>" placeholder="<?= e(t('zalo.ph_apartment')) ?>"></div>
        <div class="form-group <?= isset($errors['date_from']) ? 'has-error' : '' ?>"><label for="date_from"><?= e(t('zalo.from')) ?></label><input id="date_from" name="date_from" type="date" value="<?= e($filters['date_from'] ?? '') ?>"><?= $err('date_from') ?></div>
        <div class="form-group <?= isset($errors['date_to']) ? 'has-error' : '' ?>"><label for="date_to"><?= e(t('zalo.to')) ?></label><input id="date_to" name="date_to" type="date" value="<?= e($filters['date_to'] ?? '') ?>"><?= $err('date_to') ?></div>
        <button type="submit" class="btn btn--primary btn--sm"><?= e(t('zalo.filter')) ?></button>
        <a class="btn btn--secondary btn--sm" href="<?= e(base_url('/zalo-notifications')) ?>"><?= e(t('zalo.clear')) ?></a>
    </form>
    <?php if ($pager === null): ?>
        <div class="form-summary"><?= e(t('zalo.invalid_filter')) ?></div>
    <?php elseif ($pager['data'] === []): ?>
        <div class="empty-state"><p><?= e(t('zalo.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>#</th><th><?= e(t('zalo.recipient')) ?></th><th><?= e(t('zalo.apartment')) ?></th><th><?= e(t('zalo.period')) ?></th><th><?= e(t('zalo.status')) ?></th><th class="num"><?= e(t('zalo.attempts')) ?></th><th><?= e(t('zalo.sent_at')) ?></th><th><?= e(t('zalo.error')) ?></th><th><?= e(t('zalo.created_at')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($pager['data'] as $n): ?>
                <tr>
                    <td><a href="<?= e(base_url('/zalo-notifications/' . $n['id'])) ?>">#<?= (int) $n['id'] ?></a></td>
                    <td><?= e(($n['recipient_name'] ?? '') . ' — ' . $phoneText((string) $n['recipient_phone'])) ?></td>
                    <td><?= e($n['building_code'] . ' / ' . $n['apartment_code']) ?></td>
                    <td><?= e($n['period_code'] ?? '—') ?></td>
                    <td><?= email_status_badge($n['status']) ?><?= $n['transport'] !== 'zns' ? ' <span class="tag-note">' . e($n['transport']) . '</span>' : '' ?></td>
                    <td class="num"><?= (int) $n['attempt_count'] ?></td>
                    <td><?= e($n['sent_at'] ?? '—') ?></td>
                    <td class="cell-wrap"><?= e($n['last_error'] ?? '') ?></td>
                    <td><?= e($n['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?php if ($pager !== null): $basePath = '/zalo-notifications'; require BASE_PATH . '/resources/views/partials/pagination.php'; endif; ?>
</div>
<?php endif; ?>
