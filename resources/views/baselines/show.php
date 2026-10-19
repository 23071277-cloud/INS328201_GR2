<?php
/** @var array $b (kèm recheck, source_changed) @var ?string $blockReason */
$breadcrumbs = [[t('crumbs.baseline_targets'), null], [t('crumbs.baseline'), '/baselines'], ['#' . $b['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$unit = config('pricing.utility_unit')[$b['utility_type']];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Baseline #<?= (int) $b['id'] ?> — <?= e($b['scope_label']) ?></h1>
        <p class="page-subtitle"><?= e(config_labels('analytics.scope_type')[$b['scope_type']]) ?> · <?= e(label_of('utility_type', $b['utility_type'])) ?> · <?= e(config_labels('analytics.baseline_lifecycle')[$b['lifecycle']] ?? $b['lifecycle']) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if (can('baselines.manage') && $blockReason === null): ?>
            <a class="action-edit btn btn--ghost" href="<?= e(base_url('/baselines/' . $b['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
            <form method="post" action="<?= e(base_url('/baselines/' . $b['id'] . '/deactivate')) ?>" class="inline-form js-confirm"
                  data-confirm-title="<?= t('ui.retire_baseline') ?>" data-confirm-message="<?= t('ui.move_this_baseline_to_expired') ?>" data-confirm-button="<?= t('common.actions.deactivate') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="version_token" value="<?= e($b['version_token']) ?>">
                <button type="submit" class="btn btn--danger"><?= e(t('common.actions.deactivate')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php if ($blockReason !== null && can('baselines.manage')): ?><div class="notice-box" style="margin-bottom:16px"><?= e(message_t($blockReason)) ?></div><?php endif; ?>
<?php if ($b['source_changed']): ?>
    <div class="alert alert--error"><?= t('ui.source_data_changed_after_the_baseline') ?> <?= $b['recheck']['status'] === 'ok' ? e(format_decimal(\App\Core\Decimal::fromCents((int) $b['recheck']['value']))) : t('lit.a_result_lacking_history') ?><?= t('ui.the_stored_baseline') ?> <strong><?= t('ui.does_not_change_automatically') ?></strong> —.</div>
<?php endif; ?>
<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('common.fields.method') ?></dt><dd><?= e(config_labels('analytics.baseline_method')[$b['method']]) ?><?= $b['n_periods'] ? ' (N = ' . (int) $b['n_periods'] . ')' : '' ?></dd></div>
        <div><dt><?= t('ui.baseline_value') ?></dt><dd class="price-big"><?= $b['value_cents'] === null ? '—' : e(format_decimal(\App\Core\Decimal::fromCents($b['value_cents']))) . ' ' . e($unit) ?></dd></div>
        <div><dt><?= t('common.fields.effective_from') ?></dt><dd><?= e(format_date($b['effective_date'])) ?></dd></div>
        <div><dt><?= t('ui.stored_status') ?></dt><dd><?= $b['status'] === 'hieu_luc' ? t('lit.active') : t('lit.expired') ?></dd></div>
        <div><dt><?= t('ui.created_by_at_3') ?></dt><dd><?= e(($b['created_by_name'] ?? '—') . ' · ' . $b['created_at']) ?></dd></div>
        <?php if ($b['recheck'] !== null): ?>
            <div style="grid-column:1/-1"><dt><?= t('ui.source_periods_recalculated_today') ?></dt><dd>
                <?php foreach ($b['recheck']['periods_used'] as $p): ?><?= e($p['period_code']) ?> = <?= e(format_decimal($p['value'])) ?> <?= e($unit) ?>; <?php endforeach; ?>
                <?php foreach ($b['recheck']['skipped'] as $p): ?><span class="form-hint"><?= t('ui.skipped') ?> <?= e($p['period_code']) ?> (<?= e(message_t($p['reason'])) ?>)</span> <?php endforeach; ?>
            </dd></div>
        <?php endif; ?>
    </dl>
</div>
