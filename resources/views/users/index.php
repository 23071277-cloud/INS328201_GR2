<?php
/** @var array $filters @var array $pager */
$breadcrumbs = [[t('crumbs.system'), null], [t('page.users'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$roles    = master_labels('user_role');
$statuses = master_labels('user_status');
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.users')) ?></h1>
        <p class="page-subtitle"><?= e(t('landing.users.subtitle')) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('users.manage')): ?>
            <a class="btn btn--primary" href="<?= e(base_url('/users/create')) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= e(t('common.actions.add_user')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <!-- Filter bar — 1 hàng ngang, dàn đều -->
    <form method="get" action="<?= e(base_url('/users')) ?>" class="ulist-filter">
        <div class="ulist-filter__field ulist-filter__field--grow">
            <label for="q"><?= t('ui.search_username_full_name') ?></label>
            <div class="ulist-filter__input-wrap">
                <svg class="ulist-filter__search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input id="q" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('ui.search_username_full_name')) ?>">
            </div>
        </div>
        <div class="ulist-filter__field">
            <label for="role"><?= e(t('common.fields.role')) ?></label>
            <select id="role" name="role">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach ($roles as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $filters['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ulist-filter__field">
            <label for="status"><?= e(t('common.fields.status')) ?></label>
            <select id="status" name="status">
                <option value=""><?= e(t('common.options.all')) ?></option>
                <?php foreach ($statuses as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ulist-filter__action">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn--primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                <?= e(t('common.actions.filter')) ?>
            </button>
        </div>
    </form>

    <?php if ($pager['data'] === []): ?>
        <div class="empty-state"><p><?= e(t('landing.users.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table ulist-table">
                <thead>
                    <tr>
                        <th style="width:48px">#</th>
                        <th><?= e(t('ui.username')) ?></th>
                        <th><?= e(t('common.fields.full_name')) ?></th>
                        <th><?= e(t('common.fields.role')) ?></th>
                        <th><?= e(t('common.fields.status')) ?></th>
                        <th><?= e(t('ui.last_sign_in')) ?></th>
                        <th><?= e(t('common.fields.created_at')) ?></th>
                        <?php if (can('users.manage')): ?>
                            <th class="actions"><?= e(t('common.fields.actions')) ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pager['data'] as $row): ?>
                        <tr class="<?= $row['status'] === 'ngung' ? 'row-muted' : '' ?>">
                            <td class="ulist-table__id"><?= (int) $row['id'] ?></td>
                            <td>
                                <a href="<?= e(base_url('/users/' . $row['id'])) ?>" class="ulist-table__username">
                                    <span class="ulist-table__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($row['full_name'], 0, 1))) ?></span>
                                    <?= e($row['username']) ?>
                                </a>
                            </td>
                            <td><?= e($row['full_name']) ?></td>
                            <td><?= e(label_of('user_role', $row['role'])) ?></td>
                            <td><?= user_status_badge($row['status']) ?></td>
                            <td class="ulist-table__date"><?= e($row['last_login_at'] ?? '—') ?></td>
                            <td class="ulist-table__date"><?= e($row['created_at']) ?></td>
                            <?php if (can('users.manage')): ?>
                                <td class="actions">
                                    <a href="<?= e(base_url('/users/' . $row['id'] . '/edit')) ?>" class="action-edit btn btn--sm btn--ghost" title="<?= e(t('common.actions.edit')) ?>">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        <?= e(t('common.actions.edit')) ?>
                                    </a>
                                    <?php if ($row['status'] === 'hoat_dong'): ?>
                                        <form method="post" action="<?= e(base_url('/users/' . $row['id'] . '/lock')) ?>" class="inline-form js-confirm" data-confirm="<?= e(t('users.confirm_lock', ['name' => $row['username']])) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn--sm btn--secondary" title="<?= e(t('users.action_lock')) ?>">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                                <?= e(t('users.action_lock')) ?>
                                            </button>
                                        </form>
                                    <?php elseif ($row['status'] === 'khoa_tam'): ?>
                                        <form method="post" action="<?= e(base_url('/users/' . $row['id'] . '/unlock')) ?>" class="inline-form">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn--sm btn--secondary" title="<?= e(t('users.action_unlock')) ?>">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                                                <?= e(t('users.action_unlock')) ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php $basePath = '/users'; require BASE_PATH . '/resources/views/partials/pagination.php'; ?>
</div>
