<?php
/** @var ?array $u @var array $form */
$isEdit = $u !== null;
$breadcrumbs = [[t('crumbs.system'), null], [t('menu.users'), '/users'], [$isEdit ? t('lit.edit_2') . $u['username'] : t('lit.add'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$v = fn (string $k, string $d = '') => form_value($form, $u, $k, $d);
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= $isEdit ? t('lit.edit_account') . e($u['username']) : t('lit.add_account') ?></h1>
        <p class="page-subtitle"><?= $isEdit ? t('lit.the_username_cannot_be_changed_a') : t('lit.initial_password_per_nfr_01_at') ?></p>
    </div>
</div>
<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($isEdit ? '/users/' . $u['id'] . '/update' : '/users')) ?>" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="version_token" value="<?= e($u['version_token']) ?>"><?php endif; ?>
        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'username') ? 'has-error' : '' ?>"><label for="username"><?= t('ui.username') ?> <span class="required">*</span></label>
                <?php if ($isEdit): ?><input id="username" value="<?= e($u['username']) ?>" disabled><?php else: ?><input id="username" name="username" maxlength="50" value="<?= e($v('username')) ?>"><?php endif; ?><?= field_error($form, 'username') ?></div>
            <div class="form-group <?= has_error($form, 'full_name') ? 'has-error' : '' ?>"><label for="full_name"><?= t('common.fields.full_name') ?> <span class="required">*</span></label><input id="full_name" name="full_name" maxlength="150" value="<?= e($v('full_name')) ?>"><?= field_error($form, 'full_name') ?></div>
            <div class="form-group <?= has_error($form, 'role') ? 'has-error' : '' ?>"><label for="role"><?= t('common.fields.role') ?> <span class="required">*</span></label>
                <select id="role" name="role" <?= $isEdit && $u['is_self'] ? 'disabled' : '' ?>><option value=""><?= e(t('common.options.select')) ?></option><?php foreach (master_labels('user_role') as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('role') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <?php if ($isEdit && $u['is_self']): ?><input type="hidden" name="role" value="<?= e($u['role']) ?>"><p class="form-hint"><?= t('ui.you_cannot_change_your_own_role_2') ?></p><?php endif; ?><?= field_error($form, 'role') ?></div>
            <?php if (!$isEdit): ?>
                <div class="form-group <?= has_error($form, 'password') ? 'has-error' : '' ?>"><label for="password"><?= t('ui.initial_password') ?> <span class="required">*</span></label><input id="password" name="password" type="password" autocomplete="new-password"><?= field_error($form, 'password') ?></div>
                <div class="form-group <?= has_error($form, 'password_confirmation') ? 'has-error' : '' ?>"><label for="password_confirmation"><?= t('ui.repeat_password') ?> <span class="required">*</span></label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"><?= field_error($form, 'password_confirmation') ?></div>
            <?php endif; ?>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= e(t('common.actions.save')) ?></button><a class="btn btn--secondary" href="<?= e(base_url($isEdit ? '/users/' . $u['id'] : '/users')) ?>"><?= e(t('common.cancel')) ?></a></div>
    </form>
</div>
