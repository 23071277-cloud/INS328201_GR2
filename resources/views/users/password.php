<?php
/** @var array $u @var array $form */
$breadcrumbs = [[t('crumbs.system'), null], [t('menu.users'), '/users'], [$u['username'], '/users/' . $u['id']], [t('lit.reset_password'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.reset_password_2') ?> <?= e($u['username']) ?></h1>
        <p class="page-subtitle"><?= t('ui.passwords_are_stored_only_as_hashes') ?><?= $u['is_self'] ? t('lit.except_your_current_session') : '' ?>.</p>
    </div>
</div>
<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url('/users/' . $u['id'] . '/password')) ?>" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="version_token" value="<?= e($u['version_token']) ?>">
        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'password') ? 'has-error' : '' ?>"><label for="password"><?= t('ui.new_password') ?> <span class="required">*</span></label><input id="password" name="password" type="password" autocomplete="new-password"><?= field_error($form, 'password') ?></div>
            <div class="form-group <?= has_error($form, 'password_confirmation') ? 'has-error' : '' ?>"><label for="password_confirmation"><?= t('ui.repeat_password') ?> <span class="required">*</span></label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"><?= field_error($form, 'password_confirmation') ?></div>
        </div>
        <p class="form-hint"><?= t('ui.nfr_01_at_least_8_characters') ?></p>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= t('ui.reset_password') ?></button><a class="btn btn--secondary" href="<?= e(base_url('/users/' . $u['id'])) ?>"><?= e(t('common.cancel')) ?></a></div>
    </form>
</div>
