<?php
/** @var array $apartment @var ?array $resident @var array $form */
$isEdit = $resident !== null;
$breadcrumbs = [
    [t('menu.groups.catalog'), null],
    [t('menu.apartments'), '/apartments'],
    [$apartment['code'], '/apartments/' . $apartment['id']],
    [$isEdit ? t('lit.edit_owner_tenant') : t('lit.add_owner_tenant'), null],
];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$action = $isEdit ? '/residents/' . $resident['id'] . '/update' : '/apartments/' . $apartment['id'] . '/residents';
$selectedRole = form_value($form, $resident, 'resident_role', 'chu_ho');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= $isEdit ? t('lit.edit_owner_tenant') : t('lit.add_owner_tenant') ?></h1>
        <p class="page-subtitle"><?= t('menu.apartments') ?> <?= e($apartment['code']) ?> <?= t('ui.only_the_fields_the_system_needs') ?></p>
    </div>
</div>

<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($action)) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="updated_at" value="<?= e(form_value($form, $resident, 'updated_at')) ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'full_name') ? 'has-error' : '' ?>">
                <label for="full_name"><?= t('common.fields.full_name') ?> <span class="required">*</span></label>
                <input type="text" id="full_name" name="full_name" maxlength="150" required value="<?= e(form_value($form, $resident, 'full_name')) ?>">
                <div class="form-hint"><?= t('ui.shown_on_the_fee_notice_fn') ?></div>
                <?= field_error($form, 'full_name') ?>
            </div>
            <div class="form-group <?= has_error($form, 'resident_role') ? 'has-error' : '' ?>">
                <label for="resident_role"><?= t('common.fields.role') ?> <span class="required">*</span></label>
                <select id="resident_role" name="resident_role" required>
                    <?php foreach (master_labels('resident_role') as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedRole === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($form, 'resident_role') ?>
            </div>
            <div class="form-group <?= has_error($form, 'phone') ? 'has-error' : '' ?>">
                <label for="phone"><?= e(t('common.fields.phone')) ?></label>
                <input type="text" id="phone" name="phone" maxlength="20" inputmode="tel" autocomplete="tel" placeholder="<?= e(t('zalo.ph_phone')) ?>" value="<?= e(form_value($form, $resident, 'phone')) ?>">
                <div class="form-hint"><?= e(t('zalo.phone_hint')) ?></div>
                <div class="form-hint"><?= t('ui.optional_personal_data_nfr_06') ?></div>
                <?= field_error($form, 'phone') ?>
            </div>
            <div class="form-group <?= has_error($form, 'email') ? 'has-error' : '' ?>">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="255" autocomplete="off" value="<?= e(form_value($form, $resident, 'email')) ?>">
                <div class="form-hint"><?= t('ui.optional_used_to_send_fee_notices') ?></div>
                <?= field_error($form, 'email') ?>
            </div>
            <div class="form-group <?= has_error($form, 'effective_from') ? 'has-error' : '' ?>">
                <label for="effective_from"><?= e(t('common.fields.effective_from_date')) ?></label>
                <input type="date" id="effective_from" name="effective_from" value="<?= e(form_value($form, $resident, 'effective_from')) ?>">
                <?= field_error($form, 'effective_from') ?>
            </div>
            <div class="form-group <?= has_error($form, 'effective_to') ? 'has-error' : '' ?>">
                <label for="effective_to"><?= e(t('common.fields.to_date')) ?></label>
                <input type="date" id="effective_to" name="effective_to" value="<?= e(form_value($form, $resident, 'effective_to')) ?>">
                <div class="form-hint"><?= t('ui.leave_empty_while_still_active') ?></div>
                <?= field_error($form, 'effective_to') ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? t('lit.save_changes') : t('lit.add') ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url('/apartments/' . $apartment['id'] . '#residents')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
