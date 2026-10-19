<?php
/** @var ?array $b (sửa) @var array $form @var ?array $preview @var array $scopes */
$isEdit = $b !== null;
$breadcrumbs = [[t('crumbs.baseline_targets'), null], [t('crumbs.baseline'), '/baselines'], [$isEdit ? t('lit.edit') . $b['id'] : t('lit.create_baseline'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$record = $isEdit ? ['method' => $b['method'], 'n_periods' => $b['n_periods'], 'manual_value' => format_input($b['manual_value']), 'effective_date' => $b['effective_date']] : null;
$method = form_value($form, $record, 'method', 'trung_binh_n_ky');
$scope = form_value($form, null, 'scope');
$utility = form_value($form, null, 'utility_type', 'dien');
$units = config('pricing.utility_unit');
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= $isEdit ? t('lit.edit_baseline') . (int) $b['id'] : t('lit.create_baseline') ?></h1>
        <p class="page-subtitle"><?= t('ui.press') ?> <strong><?= t('ui.try_calculation') ?></strong> <?= t('ui.to_see_a_sample_result_and') ?> <strong><?= t('common.actions.save') ?></strong> <?= t('ui.fn_06_01_only') ?> <strong><?= t('ui.locked') ?></strong> <?= t('ui.periods_ending_before_the_effective_date') ?></p>
    </div>
</div>
<div class="panel form-card">
    <?php require BASE_PATH . '/resources/views/partials/form_alert.php'; ?>
    <form method="post" action="<?= e(base_url($isEdit ? '/baselines/' . $b['id'] . '/update' : '/baselines')) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="version_token" value="<?= e($b['version_token']) ?>"><?php endif; ?>
        <div class="form-grid">
            <?php if ($isEdit): ?>
                <div class="form-group"><label><?= e(t('common.fields.scope')) ?></label><div><?= e(config_labels('analytics.scope_type')[$b['scope_type']]) ?>: <?= e($b['scope_label']) ?> — <?= e(label_of('utility_type', $b['utility_type'])) ?></div></div>
            <?php else: ?>
                <div class="form-group <?= has_error($form, 'scope_id') ? 'has-error' : '' ?>">
                    <label for="scope"><?= t('common.fields.scope') ?> <span class="required">*</span></label>
                    <select id="scope" name="scope">
                        <option value=""><?= e(t('common.options.select')) ?></option>
                        <?php foreach ($scopes as $type => $entities): ?>
                            <optgroup label="<?= e(config_labels('analytics.scope_type')[$type]) ?>">
                                <?php foreach ($entities as $en): $v = $type . ':' . $en['id']; ?>
                                    <option value="<?= e($v) ?>" <?= $scope === $v ? 'selected' : '' ?>><?= e($en['label']) ?><?= $en['utility_type'] ? ' (' . e(label_of('utility_type', $en['utility_type'])) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($form, 'scope_id') ?>
                </div>
                <div class="form-group <?= has_error($form, 'utility_type') ? 'has-error' : '' ?>">
                    <label for="utility_type"><?= t('common.fields.utility') ?> <span class="required">*</span></label>
                    <select id="utility_type" name="utility_type">
                        <?php foreach (master_labels('utility_type') as $v => $l): ?><option value="<?= e($v) ?>" <?= $utility === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                    <?= field_error($form, 'utility_type') ?>
                </div>
            <?php endif; ?>
            <div class="form-group <?= has_error($form, 'method') ? 'has-error' : '' ?>">
                <label for="method"><?= t('common.fields.method') ?> <span class="required">*</span></label>
                <select id="method" name="method">
                    <?php foreach (config_labels('analytics.baseline_method') as $v => $l): ?><option value="<?= e($v) ?>" <?= $method === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
                <?= field_error($form, 'method') ?>
            </div>
            <div class="form-group <?= has_error($form, 'n_periods') ? 'has-error' : '' ?>">
                <label for="n_periods"><?= t('ui.n_periods_for_average') ?></label>
                <input type="text" inputmode="numeric" id="n_periods" name="n_periods" value="<?= e(form_value($form, $record, 'n_periods', (string) config('analytics.default_n_periods'))) ?>">
                <div class="form-hint">≥ <?= (int) config('analytics.min_reliable_periods') ?> <?= t('ui.periods_recommended_t2_question_25') ?></div>
                <?= field_error($form, 'n_periods') ?>
            </div>
            <div class="form-group <?= has_error($form, 'manual_value') ? 'has-error' : '' ?>">
                <label for="manual_value"><?= t('ui.manual_value_for_manual_entry') ?></label>
                <input type="text" inputmode="decimal" id="manual_value" name="manual_value" value="<?= e(form_value($form, $record, 'manual_value')) ?>">
                <?= field_error($form, 'manual_value') ?>
            </div>
            <div class="form-group <?= has_error($form, 'effective_date') ? 'has-error' : '' ?>">
                <label for="effective_date"><?= t('ui.effective_date') ?> <span class="required">*</span></label>
                <input type="date" id="effective_date" name="effective_date" value="<?= e(form_value($form, $record, 'effective_date')) ?>">
                <?= field_error($form, 'effective_date') ?>
            </div>
        </div>
        <?php if ($preview !== null): ?>
            <div class="notice-box" style="margin:12px 0">
                <strong><?= t('ui.trial_result') ?></strong>
                <?php if ($preview['status'] === 'ok'): ?>
                    baseline = <strong><?= e(format_decimal(\App\Core\Decimal::fromCents((int) $preview['value']))) ?></strong>
                <?php else: ?>
                    <span class="text-danger"><?= e((string) $preview['message']) ?></span>
                <?php endif; ?>
                <?php if ($preview['periods_used'] !== []): ?><div class="form-hint"><?= t('ui.source_periods') ?> <?php foreach ($preview['periods_used'] as $p): ?><?= e($p['period_code']) ?> = <?= e(format_decimal($p['value'])) ?> (<?= e($p['reason']) ?>); <?php endforeach; ?></div><?php endif; ?>
                <?php if ($preview['skipped'] !== []): ?><div class="form-hint"><?= t('ui.skipped_2') ?> <?php foreach ($preview['skipped'] as $p): ?><?= e($p['period_code']) ?> (<?= e(message_t($p['reason'])) ?>); <?php endforeach; ?></div><?php endif; ?>
                <?php foreach ($preview['warnings'] as $w): ?><div class="form-hint text-danger"><?= e(message_t($w)) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" name="intent" value="preview" class="btn btn--ghost"><?= t('ui.try_calculation') ?></button>
            <button type="submit" name="intent" value="save" class="btn btn--primary"><?= t('ui.save_baseline') ?></button>
            <a class="btn btn--secondary" href="<?= e(base_url($isEdit ? '/baselines/' . $b['id'] : '/baselines')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</div>
