<?php
/** @var array $u @var ?array $logs */
$breadcrumbs = [[t('crumbs.system'), null], [t('menu.users'), '/users'], [$u['username'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$actions = ['hoat_dong' => ['deactivate'], 'khoa_tam' => ['unlock', 'deactivate'], 'ngung' => ['activate']][$u['status']] ?? [];
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($u['username']) ?> — <?= e($u['full_name']) ?></h1>
        <p class="page-subtitle"><?= user_status_badge($u['status']) ?> <?= e(label_of('user_role', $u['role'])) ?><?= $u['is_self'] ? t('lit.strong_your_account_strong') : '' ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('users.manage')): ?><a class="action-edit btn btn--ghost" href="<?= e(base_url('/users/' . $u['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a><?php endif; ?>
        <?php if (can('users.reset_password')): ?><a class="btn btn--ghost" href="<?= e(base_url('/users/' . $u['id'] . '/password')) ?>"><?= t('ui.reset_password') ?></a><?php endif; ?>
        <?php if (can('users.change_status') && !$u['is_self']): ?>
            <?php foreach ($actions as $act): ?>
                <form method="post" action="<?= e(base_url('/users/' . $u['id'] . '/status')) ?>" class="inline-form js-confirm"
                      data-confirm-title="<?= e(message_t(\App\Services\UserService::STATUS_ACTIONS[$act])) ?>"
                      data-confirm-message="<?= $act === 'deactivate' ? t('lit.disable_the_user_can_no_longer') : t('lit.allow_the_account_to_sign_in') ?>" data-confirm-button="<?= t('ui.ok') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="version_token" value="<?= e($u['version_token']) ?>">
                    <input type="hidden" name="action" value="<?= e($act) ?>">
                    <button type="submit" class="btn <?= $act === 'deactivate' ? 'btn--danger' : 'btn--primary' ?>"><?= e(message_t(\App\Services\UserService::STATUS_ACTIONS[$act])) ?></button>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('ui.username') ?></dt><dd><?= e($u['username']) ?></dd></div>
        <div><dt><?= t('common.fields.full_name') ?></dt><dd><?= e($u['full_name']) ?></dd></div>
        <div><dt><?= t('common.fields.role') ?></dt><dd><?= e(label_of('user_role', $u['role'])) ?></dd></div>
        <div><dt><?= t('common.fields.status') ?></dt><dd><?= user_status_badge($u['status']) ?><?= $u['locked_until'] ? t('lit.until') . e($u['locked_until']) : '' ?></dd></div>
        <div><dt><?= t('ui.failed_sign_ins') ?></dt><dd><?= (int) $u['failed_login_count'] ?></dd></div>
        <div><dt><?= t('ui.last_sign_in') ?></dt><dd><?= e($u['last_login_at'] ?? '—') ?></dd></div>
        <div><dt><?= t('ui.created_by_at_2') ?></dt><dd><?= e(($u['created_by_name'] ?? '—') . ' · ' . $u['created_at']) ?></dd></div>
        <div><dt><?= t('alert_j16.update') ?></dt><dd><?= e($u['updated_at']) ?></dd></div>
    </dl>
    <?php if ($u['is_self']): ?><p class="form-hint"><?= t('ui.you_cannot_change_your_own_role') ?></p><?php endif; ?>
</div>
<?php if ($logs !== null): ?>
<div class="panel">
    <h2 class="panel__title"><?= t('ui.recent_activity_of_this_account_20') ?></h2>
    <?php if ($logs === []): ?><div class="empty-state"><p><?= t('ui.no_records_yet') ?></p></div><?php else: ?>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>#</th><th><?= e(t('common.fields.timestamp')) ?></th><th><?= e(t('common.fields.actor')) ?></th><th><?= e(t('common.fields.action')) ?></th><th>IP</th></tr></thead>
        <tbody><?php foreach ($logs as $log): ?>
            <tr><td><a href="<?= e(base_url('/audit-logs/' . $log['id'])) ?>">#<?= (int) $log['id'] ?></a></td><td><?= e($log['created_at']) ?></td><td><?= e($log['user_name'] ?? t('crumbs.system')) ?></td><td><?= e($log['action']) ?></td><td><?= e($log['ip_address'] ?? '—') ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <p class="form-hint"><a href="<?= e(url_with('/audit-logs', ['entity_type' => 'users', 'entity_id' => (string) $u['id']])) ?>"><?= t('ui.see_everything_in_the_audit_log') ?></a></p>
    <?php endif; ?>
</div>
<?php endif; ?>
