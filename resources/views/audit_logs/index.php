<?php
/** @var array $filters @var ?array $pager @var array $errors @var array $options */
$breadcrumbs = [[t('crumbs.system'), null], [t('page.audit_logs'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="form-error">' . e($errors[$k][0]) . '</div>' : '';
$summary = static function ($value): string {
    if ($value === null) {
        return '—';
    }
    $text = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value;
    return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '…' : $text;
};

/* Badge màu theo action */
$actionBadge = static function (string $action): string {
    $map = [
        'login_success' => ['audit-badge--success', '✔'],
        'login_failed'  => ['audit-badge--danger',  '✘'],
        'logout'        => ['audit-badge--muted',   '↩'],
        'create'        => ['audit-badge--info',    '+'],
        'update'        => ['audit-badge--warning', '~'],
        'delete'        => ['audit-badge--danger',  '✘'],
    ];
    [$cls, $ico] = $map[$action] ?? ['audit-badge--neutral', '·'];
    return '<span class="audit-badge ' . $cls . '"><span aria-hidden="true">' . $ico . '</span> ' . e($action) . '</span>';
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.audit_logs')) ?></h1>
        <p class="page-subtitle"><?= e(t('ui.read_only_br_04_sensitive_values')) ?></p>
    </div>
</div>

<div class="panel">
    <!-- Bộ lọc: 4 cột đều × 2 hàng + hàng cuối nút -->
    <form method="get" action="<?= e(base_url('/audit-logs')) ?>" class="audit-filter">

        <div class="audit-filter__grid">
            <!-- Hàng 1 -->
            <div class="form-group <?= isset($errors['user_id']) ? 'has-error' : '' ?>">
                <label for="user_id"><?= e(t('common.fields.actor')) ?></label>
                <select id="user_id" name="user_id">
                    <option value=""><?= e(t('common.options.all')) ?></option>
                    <?php foreach ($options['users'] as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>" <?= $filters['user_id'] === (string) $opt['id'] ? 'selected' : '' ?>>
                            <?= e($opt['username'] . ' — ' . $opt['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $err('user_id') ?>
            </div>

            <div class="form-group <?= isset($errors['action']) ? 'has-error' : '' ?>">
                <label for="action"><?= e(t('common.fields.action')) ?></label>
                <select id="action" name="action">
                    <option value=""><?= e(t('common.options.all')) ?></option>
                    <?php foreach ($options['actions'] as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filters['action'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('action') ?>
            </div>

            <div class="form-group <?= isset($errors['entity_type']) ? 'has-error' : '' ?>">
                <label for="entity_type"><?= e(t('ui.entity_type')) ?></label>
                <select id="entity_type" name="entity_type">
                    <option value=""><?= e(t('common.options.all')) ?></option>
                    <?php foreach ($options['entity_types'] as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filters['entity_type'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('entity_type') ?>
            </div>

            <div class="form-group <?= isset($errors['entity_id']) ? 'has-error' : '' ?>">
                <label for="entity_id"><?= e(t('ui.entity_id')) ?></label>
                <input id="entity_id" name="entity_id" maxlength="50" value="<?= e($filters['entity_id']) ?>">
                <?= $err('entity_id') ?>
            </div>

            <!-- Hàng 2 -->
            <div class="form-group <?= isset($errors['date_from']) ? 'has-error' : '' ?>">
                <label for="date_from"><?= e(t('common.fields.from_date')) ?></label>
                <input id="date_from" name="date_from" type="date" value="<?= e($filters['date_from']) ?>">
                <?= $err('date_from') ?>
            </div>

            <div class="form-group <?= isset($errors['date_to']) ? 'has-error' : '' ?>">
                <label for="date_to"><?= e(t('common.fields.to_date')) ?></label>
                <input id="date_to" name="date_to" type="date" value="<?= e($filters['date_to']) ?>">
                <?= $err('date_to') ?>
            </div>

            <div class="form-group <?= isset($errors['ip_address']) ? 'has-error' : '' ?>">
                <label for="ip_address">IP</label>
                <input id="ip_address" name="ip_address" maxlength="45" placeholder="0.0.0.0" value="<?= e($filters['ip_address']) ?>">
                <?= $err('ip_address') ?>
            </div>

            <!-- Ô cuối hàng 2: 2 nút hành động -->
            <div class="audit-filter__actions">
                <label>&nbsp;</label>
                <div class="audit-filter__btns">
                    <button type="submit" class="btn btn--primary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <?= e(t('common.actions.filter')) ?>
                    </button>
                    <a class="btn btn--secondary" href="<?= e(base_url('/audit-logs')) ?>">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <?= e(t('common.actions.clear_filters')) ?>
                    </a>
                </div>
            </div>
        </div>
    </form>

    <?php if ($pager === null): ?>
        <div class="form-summary"><?= t('ui.invalid_filter_see_the_error_on') ?></div>
    <?php elseif ($pager['data'] === []): ?>
        <div class="empty-state"><p><?= e(t('landing.audit_logs.empty')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table audit-table">
                <thead>
                    <tr>
                        <th style="width:56px">#</th>
                        <th style="width:148px"><?= e(t('common.fields.timestamp')) ?></th>
                        <th><?= e(t('common.fields.actor')) ?></th>
                        <th style="width:160px"><?= e(t('common.fields.action')) ?></th>
                        <th><?= e(t('ui.entity')) ?></th>
                        <th class="audit-table__val"><?= e(t('common.fields.old_value')) ?></th>
                        <th class="audit-table__val"><?= e(t('common.fields.new_value')) ?></th>
                        <th style="width:110px">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pager['data'] as $log): ?>
                        <tr>
                            <td class="audit-table__id">
                                <a href="<?= e(base_url('/audit-logs/' . $log['id'])) ?>">#<?= (int) $log['id'] ?></a>
                            </td>
                            <td class="audit-table__ts"><?= e($log['created_at']) ?></td>
                            <td class="audit-table__actor"><?= e($log['username'] ?? t('crumbs.system')) ?></td>
                            <td><?= $actionBadge($log['action']) ?></td>
                            <td class="audit-table__entity"><?= e($log['entity_type'] . ($log['entity_id'] !== null ? ' #' . $log['entity_id'] : '')) ?></td>
                            <td class="audit-table__val">
                                <?php $old = $summary($log['old_value']); ?>
                                <?php if ($old !== '—'): ?>
                                    <code class="audit-table__code"><?= e($old) ?></code>
                                <?php else: ?>
                                    <span class="audit-table__dash">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="audit-table__val">
                                <?php $new = $summary($log['new_value']); ?>
                                <?php if ($new !== '—'): ?>
                                    <code class="audit-table__code"><?= e($new) ?></code>
                                <?php else: ?>
                                    <span class="audit-table__dash">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="audit-table__ip"><?= e($log['ip_address'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($pager !== null): $basePath = '/audit-logs'; require BASE_PATH . '/resources/views/partials/pagination.php'; endif; ?>
</div>
