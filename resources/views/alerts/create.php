<?php
/** @var array $form @var array $options */
$breadcrumbs = [[t('crumbs.alerts_fn07'), null], [t('alert_show.list'), '/alerts'], [t('lit.create_manually'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$v = fn (string $k, string $d = '') => form_value($form, null, $k, $d);
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.create_manual_alert') ?></h1>
        <p class="page-subtitle"><?= t('ui.fn_07_03_technical_staff_report') ?></p>
    </div>
</div>
<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url('/alerts')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="form-group <?= has_error($form, 'apartment_id') ? 'has-error' : '' ?>"><label for="apartment_id"><?= t('menu.apartments') ?> <span class="required">*</span></label>
                <select id="apartment_id" name="apartment_id"><option value=""><?= e(t('common.options.select')) ?></option><?php foreach ($options['apartments'] as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $v('apartment_id') === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['label']) ?></option><?php endforeach; ?></select><?= field_error($form, 'apartment_id') ?></div>
            <div class="form-group <?= has_error($form, 'meter_id') ? 'has-error' : '' ?>"><label for="meter_id"><?= t('ui.meter_optional') ?></label>
                <select id="meter_id" name="meter_id"><option value=""><?= e(t('common.options.no_meter')) ?></option><?php foreach ($options['meters'] as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $v('meter_id') === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['label']) ?> (<?= e(label_of('utility_type', $m['utility_type'])) ?>)</option><?php endforeach; ?></select><?= field_error($form, 'meter_id') ?></div>
            <div class="form-group <?= has_error($form, 'utility_type') ? 'has-error' : '' ?>"><label for="utility_type"><?= t('common.fields.utility') ?> <span class="required">*</span></label>
                <select id="utility_type" name="utility_type"><?php foreach (master_labels('utility_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('utility_type', 'dien') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= field_error($form, 'utility_type') ?></div>
            <div class="form-group <?= has_error($form, 'billing_period_id') ? 'has-error' : '' ?>"><label for="billing_period_id"><?= t('common.fields.period') ?> <span class="required">*</span></label>
                <select id="billing_period_id" name="billing_period_id"><?php foreach ($options['periods'] as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $v('billing_period_id') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['period_code']) ?></option><?php endforeach; ?></select><?= field_error($form, 'billing_period_id') ?></div>
            <div class="form-group <?= has_error($form, 'priority') ? 'has-error' : '' ?>"><label for="priority"><?= t('ui.suggested_priority') ?></label>
                <select id="priority" name="priority"><?php foreach (config_labels('alerts.priority') as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('priority', 'trung_binh') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= field_error($form, 'priority') ?></div>
            <div class="form-group <?= has_error($form, 'description') ? 'has-error' : '' ?>" style="grid-column:1/-1"><label for="description"><?= t('ui.observed_issue') ?> <span class="required">*</span></label>
                <textarea id="description" name="description" rows="3" maxlength="500"><?= e($v('description')) ?></textarea><?= field_error($form, 'description') ?></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= t('ui.create_alert') ?></button><a class="btn btn--secondary" href="<?= e(base_url('/alerts')) ?>"><?= e(t('common.cancel')) ?></a></div>
    </form>
</div>
