<?php
/** @var array $periods @var array $filters @var ?array $table */
$breadcrumbs = [[t('crumbs.baseline_targets'), null], [t('page.comparisons'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$unit = config('pricing.utility_unit')[$filters['utility_type']];

/**
 * Render badge cho % thay đổi: dương → cam/đỏ, âm → xanh lá, bằng 0 → xám
 */
$pctBadge = static function (?string $pct, ?string $diffStr): string {
    if ($pct === null || $diffStr === null) {
        return '<span class="cmp-badge cmp-badge--neutral">—</span>';
    }
    $diff = (float) $diffStr;
    $text = pct_text($pct);
    if ($diff > 0) {
        return '<span class="cmp-badge cmp-badge--up">▲ ' . e($text) . '</span>';
    }
    if ($diff < 0) {
        return '<span class="cmp-badge cmp-badge--down">▼ ' . e($text) . '</span>';
    }
    return '<span class="cmp-badge cmp-badge--neutral">= ' . e($text) . '</span>';
};

$trendBadge = static function (?string $trend): string {
    return match ($trend) {
        'up'    => '<span class="cmp-badge cmp-badge--up">▲ ' . e(t('helpers.trend_up')) . '</span>',
        'down'  => '<span class="cmp-badge cmp-badge--down">▼ ' . e(t('helpers.trend_down')) . '</span>',
        'equal' => '<span class="cmp-badge cmp-badge--neutral">= ' . e(t('helpers.trend_equal')) . '</span>',
        default => '<span class="cmp-badge cmp-badge--neutral">—</span>',
    };
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.comparisons')) ?></h1>
    </div>
</div>

<!-- Filter card -->
<div class="cmp-filter-card">
    <form method="get" action="<?= e(base_url('/comparisons')) ?>" class="cmp-filter-form">
        <div class="cmp-filter-form__fields">
            <div class="form-group">
                <label for="period_id"><?= e(t('common.fields.period')) ?></label>
                <select id="period_id" name="period_id">
                    <?php foreach ($periods as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $filters['period_id'] === (string) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['period_code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="scope_type"><?= e(t('common.fields.scope')) ?></label>
                <select id="scope_type" name="scope_type">
                    <?php foreach (config_labels('analytics.scope_type') as $v => $l): ?>
                        <option value="<?= e($v) ?>" <?= $filters['scope_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="utility_type"><?= e(t('common.fields.utility')) ?></label>
                <select id="utility_type" name="utility_type">
                    <?php foreach (master_labels('utility_type') as $v => $l): ?>
                        <option value="<?= e($v) ?>" <?= $filters['utility_type'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn--primary cmp-filter-form__btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Xem
        </button>
    </form>
</div>

<!-- Result card -->
<?php if ($table === null): ?>
    <div class="cmp-data-card">
        <div class="dash-empty-state">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <p><?= e(t('landing.comparisons.empty')) ?></p>
        </div>
    </div>
<?php else: ?>
    <!-- Meta bar: period info + unit -->
    <div class="cmp-meta-bar">
        <span class="cmp-meta-bar__item">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <?= e($table['period']['period_code']) ?>
            <span class="cmp-meta-bar__status"><?= e(config_labels('readings.period_status')[$table['period']['status']] ?? '') ?></span>
        </span>
        <?php if ($table['previous']): ?>
        <span class="cmp-meta-bar__sep">·</span>
        <span class="cmp-meta-bar__item"><?= e(t('ui.previous_period_label')) ?>: <strong><?= e($table['previous']['period_code']) ?></strong></span>
        <?php endif; ?>
        <span class="cmp-meta-bar__sep">·</span>
        <span class="cmp-meta-bar__item"><?= e(t('ui.unit_label')) ?>: <strong><?= e($unit) ?></strong></span>
    </div>

    <div class="cmp-data-card">
        <div class="table-wrap cmp-table-wrap">
            <table class="data-table cmp-table">
                <thead>
                    <tr>
                        <th class="cmp-th-scope"><?= e(t('common.fields.scope')) ?></th>
                        <th class="num"><?= e(t('common.fields.current')) ?></th>
                        <th class="num"><?= e(t('alert_show.previous_period')) ?></th>
                        <th class="num"><?= e(t('ui.vs_previous_period')) ?></th>
                        <th class="num">Baseline</th>
                        <th class="num"><?= e(t('common.fields.difference')) ?></th>
                        <th class="num"><?= e(t('common.fields.change_pct')) ?></th>
                        <th><?= e(t('alert_show.trend')) ?></th>
                        <th class="num"><?= e(t('ui.cost_reference')) ?></th>
                        <th class="cmp-th-note"><?= e(t('common.fields.note')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($table['rows'] as $r): ?>
                    <tr class="cmp-row<?= $r['data_status'] !== 'ok' ? ' cmp-row--muted' : '' ?>">
                        <!-- Scope -->
                        <td class="cmp-td-scope">
                            <span class="cmp-scope-label"><?= e($r['scope_label']) ?></span>
                            <?= $r['scope_status'] !== 'hoat_dong' ? t('lit.span_class_tag_note_inactive_span') : '' ?>
                        </td>

                        <!-- Current -->
                        <td class="num cmp-num">
                            <?= $r['current_value'] === null ? '<span class="cmp-dash">—</span>' : e(format_decimal($r['current_value'])) ?>
                        </td>

                        <!-- Previous period value -->
                        <td class="num cmp-num">
                            <?= $r['previous_value'] === null ? '<span class="cmp-dash">—</span>' : e(format_decimal($r['previous_value'])) ?>
                        </td>

                        <!-- % vs previous -->
                        <td class="num">
                            <?= $r['previous_value'] === null
                                ? '<span class="cmp-badge cmp-badge--neutral">—</span>'
                                : $pctBadge($r['previous_percentage_change'], $r['previous_percentage_change']) ?>
                        </td>

                        <!-- Baseline -->
                        <td class="num cmp-num">
                            <?php if ($r['baseline_value'] === null): ?>
                                <span class="cmp-dash">—</span>
                            <?php else: ?>
                                <?= e(format_decimal($r['baseline_value'])) ?>
                                <?php if (can('baselines.view')): ?>
                                    <a href="<?= e(base_url('/baselines/' . $r['baseline_id'])) ?>" class="cmp-baseline-link">#<?= (int) $r['baseline_id'] ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <!-- Difference -->
                        <td class="num cmp-num">
                            <?= $r['difference'] === null ? '<span class="cmp-dash">—</span>' : e(format_decimal($r['difference'])) ?>
                        </td>

                        <!-- % vs baseline badge -->
                        <td class="num">
                            <?= $pctBadge($r['percentage_change'] ?? null, $r['difference'] ?? null) ?>
                        </td>

                        <!-- Trend badge -->
                        <td><?= $trendBadge($r['trend'] ?? null) ?></td>

                        <!-- Cost -->
                        <td class="num cmp-num">
                            <?php if ($r['current_cost'] === null): ?>
                                <span class="cmp-dash">—</span>
                            <?php else: ?>
                                <?= e(money_vnd($r['current_cost'])) ?>
                                <?php if ($r['previous_cost'] !== null): ?>
                                    <div class="cmp-cost-prev"><?= e(money_vnd($r['previous_cost'])) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <!-- Note -->
                        <td class="cmp-td-note">
                            <?php $msg = message_t((string) $r['message']); ?>
                            <?php if ($msg !== ''): ?>
                                <span class="cmp-note-text"><?= e($msg) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
