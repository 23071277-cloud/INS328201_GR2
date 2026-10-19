<?php
/** @var array $apartment @var array<string,int> $quantities @var array $form */
$breadcrumbs = [
    [t('menu.groups.catalog'), null],
    [t('menu.apartments'), '/apartments'],
    [$apartment['code'], '/apartments/' . $apartment['id']],
    [t('lit.parked_vehicles'), null],
];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.parked_vehicles_2') ?> <?= e($apartment['code']) ?></h1>
        <p class="page-subtitle"><?= t('ui.fn_02_06_only_vehicle_counts') ?></p>
    </div>
</div>

<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url('/apartments/' . $apartment['id'] . '/vehicles')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
            <?php foreach (master_labels('vehicle_type') as $type => $label): ?>
                <?php $field = 'quantity_' . $type; ?>
                <div class="form-group <?= has_error($form, $field) ? 'has-error' : '' ?>">
                    <label for="<?= e($field) ?>"><?= e($label) ?> <span class="required">*</span></label>
                    <input type="number" min="0" step="1" id="<?= e($field) ?>" name="<?= e($field) ?>" required
                           value="<?= e(form_value($form, null, $field, (string) ($quantities[$type] ?? 0))) ?>">
                    <?= field_error($form, $field) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-hint" style="margin-bottom:12px"><?= t('ui.integer_0_br_10_val_02') ?></div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= e(t('common.actions.save')) ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url('/apartments/' . $apartment['id'] . '#vehicles')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
