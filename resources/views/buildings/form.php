<?php
/** @var ?array $building null = tạo mới @var array $form */
$isEdit = $building !== null;
$breadcrumbs = [[t('menu.groups.catalog'), null], [t('menu.buildings'), '/buildings']];
if ($isEdit) {
    $breadcrumbs[] = [$building['code'], '/buildings/' . $building['id']];
}
$breadcrumbs[] = [$isEdit ? t('common.actions.edit') : t('lit.add_new'), null];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$action = $isEdit ? '/buildings/' . $building['id'] . '/update' : '/buildings';
?>
<div class="page-head">
    <h1 class="page-title"><?= $isEdit ? t('lit.edit_building') : t('lit.add_building') ?></h1>
</div>

<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($action)) ?>" novalidate<?= $isEdit ? '' : ' data-building-suggest' ?>>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="updated_at" value="<?= e(form_value($form, $building, 'updated_at')) ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'code') ? 'has-error' : '' ?>">
                <label for="code"><?= t('ui.building_code') ?> <span class="required">*</span></label>
                <input type="text" id="code" name="code" maxlength="30" required value="<?= e(form_value($form, $building, 'code')) ?>" aria-describedby="building_code_hint">
                <div class="form-hint" id="building_code_hint"><?php if (!$isEdit): ?><?= t('ui.the_building_code_is_suggested_from') ?> <?php endif; ?><?= t('ui.letters_digits_and_no_spaces_up') ?></div>
                <?= field_error($form, 'code') ?>
            </div>
            <div class="form-group <?= has_error($form, 'name') ? 'has-error' : '' ?>">
                <label for="name"><?= t('common.fields.building_name') ?> <span class="required">*</span></label>
                <input type="text" id="name" name="name" maxlength="150" required value="<?= e(form_value($form, $building, 'name')) ?>">
                <?= field_error($form, 'name') ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? t('lit.save_changes') : t('lit.add_building') ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url($isEdit ? '/buildings/' . $building['id'] : '/buildings')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
