<?php
/** @var ?array $t (sửa) @var array $form @var array $scopes @var array $periods */
$isEdit = $t !== null;
$breadcrumbs = [[t('crumbs.baseline_targets'), null], [t('lit.targets'), '/targets'], [$isEdit ? t('lit.edit') . $t['id'] : t('lit.set_target'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$scope = form_value($form, null, 'scope');
$utility = form_value($form, null, 'utility_type', 'dien');
$period = form_value($form, null, 'billing_period_id');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= $isEdit ? t('lit.edit_target') . (int) $t['id'] : t('lit.set_consumption_target') ?></h1>
        <p class="page-subtitle"><?= t('guide.targets_form') ?></p>
    </div>
</div>
<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($isEdit ? '/targets/' . $t['id'] . '/update' : '/targets')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
            <?php if ($isEdit): ?>
                <input type="hidden" name="version_token" value="<?= e($t['version_token']) ?>">
                <div class="form-group"><label><?= t('ui.scope_period') ?></label><div><?= e(config_labels('analytics.scope_type')[$t['scope_type']]) ?>: <?= e($t['scope_label']) ?> — <?= e(label_of('utility_type', $t['utility_type'])) ?> <?= t('ui.period') ?> <?= e($t['period_code']) ?></div></div>
            <?php else: ?>
                <div class="form-group <?= has_error($form, 'scope_id') ? 'has-error' : '' ?>">
                    <label for="scope"><?= t('common.fields.scope') ?> <span class="required">*</span></label>
                    <select id="scope" name="scope"><option value=""><?= e(t('common.options.select')) ?></option>
                        <?php foreach ($scopes as $type => $entities): ?>
                            <optgroup label="<?= e(config_labels('analytics.scope_type')[$type]) ?>">
                                <?php foreach ($entities as $en): $v = $type . ':' . $en['id']; ?><option value="<?= e($v) ?>" <?= $scope === $v ? 'selected' : '' ?>><?= e($en['label']) ?></option><?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($form, 'scope_id') ?>
                </div>
                <div class="form-group <?= has_error($form, 'utility_type') ? 'has-error' : '' ?>">
                    <label for="utility_type"><?= t('common.fields.utility') ?> <span class="required">*</span></label>
                    <select id="utility_type" name="utility_type"><?php foreach (master_labels('utility_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $utility === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                    <?= field_error($form, 'utility_type') ?>
                </div>
                <div class="form-group <?= has_error($form, 'billing_period_id') ? 'has-error' : '' ?>">
                    <label for="billing_period_id"><?= t('ui.applicable_period') ?> <span class="required">*</span></label>
                    <select id="billing_period_id" name="billing_period_id"><option value=""><?= e(t('common.options.select')) ?></option><?php foreach ($periods as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $period === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['period_code']) ?></option><?php endforeach; ?></select>
                    <?= field_error($form, 'billing_period_id') ?>
                </div>
            <?php endif; ?>
            <div class="form-group <?= has_error($form, 'target_value') ? 'has-error' : '' ?>">
                <label for="target_value"><?= t('ui.target_do_not_exceed') ?> <span class="required">*</span></label>
                <input type="text" inputmode="decimal" id="target_value" name="target_value" value="<?= e(form_value($form, $isEdit ? ['target_value' => format_input($t['target_value'])] : null, 'target_value')) ?>">
                <?= field_error($form, 'target_value') ?>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= e(t('common.actions.save')) ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url('/targets')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
