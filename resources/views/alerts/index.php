<?php
/** @var array $filters @var array $rows @var array $periods @var array $zones @var array $rules */
$breadcrumbs = [[t('crumbs.alerts_fn07'), null], [t('crumbs.alert_list'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';

$opt = function (string $name, array $options, string $current, ?string $all = null): string {
    $all ??= t('common.options.all');
    $html = '<select id="' . e($name) . '" name="' . e($name) . '"><option value="">' . e($all) . '</option>';
    foreach ($options as $v => $l) {
        $html .= '<option value="' . e((string) $v) . '"'
               . ((string) $v === $current ? ' selected' : '') . '>'
               . e($l) . '</option>';
    }
    return $html . '</select>';
};

/* % change badge */
$pctBadge = static function (?string $pct): string {
    if ($pct === null) return '<span class="alt-dash">—</span>';
    $val = (float) $pct;
    $text = pct_text($pct);
    if ($val > 0) return '<span class="alt-badge alt-badge--up">▲ ' . e($text) . '</span>';
    if ($val < 0) return '<span class="alt-badge alt-badge--down">▼ ' . e($text) . '</span>';
    return '<span class="alt-badge alt-badge--neutral">= ' . e($text) . '</span>';
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.alerts')) ?></h1>
    </div>
    <div class="page-head__actions">
        <?php if (can('alerts.create_manual')): ?>
            <a class="btn btn--primary" href="<?= e(base_url('/alerts/create')) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= e(t('ui.manual_alert')) ?>
            </a>
        <?php endif; ?>
        <?php if (can('alert_rules.view')): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('/alert-rules')) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                <?= e(t('menu.alert_rules')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Evaluate card -->
<?php if (can('alerts.evaluate')): ?>
<div class="alt-eval-card no-print">
    <form method="post" action="<?= e(base_url('/alerts/evaluate')) ?>"
          class="alt-eval-form js-confirm"
          data-confirm-title="<?= e(t('ui.evaluate_thresholds')) ?>"
          data-confirm-message="<?= e(t('ui.evaluate_every_rule_that_is_on')) ?>"
          data-confirm-button="<?= e(t('ui.evaluate')) ?>">
        <?= csrf_field() ?>
        <div class="alt-eval-form__left">
            <div class="alt-eval-form__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </div>
            <div>
                <div class="alt-eval-form__title"><?= e(t('ui.evaluate_thresholds')) ?></div>
                <div class="alt-eval-form__sub"><?= e(t('ui.evaluate_thresholds_for_period')) ?></div>
            </div>
        </div>
        <div class="alt-eval-form__right">
            <select id="eval_period" name="period_id" class="alt-eval-select">
                <?php foreach ($periods as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $filters['period_id'] === (string) $p['id'] ? 'selected' : '' ?>>
                        <?= e($p['period_code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn--primary alt-eval-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <?= e(t('ui.evaluate_thresholds')) ?>
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Filter card -->
<div class="alt-filter-card no-print">
    <form method="get" action="<?= e(base_url('/alerts')) ?>" class="alt-filter-form">
        <div class="alt-filter-form__fields">
            <div class="form-group">
                <label for="period_id"><?= e(t('common.fields.period')) ?></label>
                <?= $opt('period_id', array_column($periods, 'period_code', 'id'), $filters['period_id']) ?>
            </div>
            <div class="form-group">
                <label for="status"><?= e(t('common.fields.status')) ?></label>
                <?= $opt('status', ['all' => t('common.options.all')] + config_labels('alerts.status'), $filters['status'], t('lit.not_closed')) ?>
            </div>
            <div class="form-group">
                <label for="priority"><?= e(t('common.fields.priority')) ?></label>
                <?= $opt('priority', config_labels('alerts.priority'), $filters['priority']) ?>
            </div>
            <div class="form-group">
                <label for="utility_type"><?= e(t('common.fields.utility')) ?></label>
                <?= $opt('utility_type', master_labels('utility_type'), $filters['utility_type']) ?>
            </div>
            <div class="form-group">
                <label for="zone_id">Khu</label>
                <?= $opt('zone_id', array_column($zones, 'label', 'id'), $filters['zone_id']) ?>
            </div>
            <div class="form-group">
                <label for="source"><?= e(t('common.fields.source')) ?></label>
                <?= $opt('source', config_labels('alerts.source'), $filters['source']) ?>
            </div>
            <div class="form-group">
                <label for="alert_type"><?= e(t('common.fields.alert_type')) ?></label>
                <?= $opt('alert_type', config_labels('alerts.alert_type'), $filters['alert_type']) ?>
            </div>
            <div class="form-group">
                <label for="rule_id"><?= e(t('common.fields.rule')) ?></label>
                <?= $opt('rule_id', array_column($rules, 'name', 'id'), $filters['rule_id']) ?>
            </div>
            <div class="form-group">
                <label for="q"><?= e(t('common.fields.apartment_code_short')) ?></label>
                <input id="q" name="q" value="<?= e($filters['q']) ?>">
            </div>
        </div>
        <div class="alt-filter-form__actions">
            <button type="submit" class="btn btn--primary alt-filter-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                <?= e(t('common.actions.filter')) ?>
            </button>
            <a class="btn alt-filter-clear" href="<?= e(base_url('/alerts')) ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <?= e(t('common.actions.clear_filters')) ?>
            </a>
        </div>
    </form>
</div>

<!-- Table card -->
<div class="alt-data-card">
    <?php if ($rows === []): ?>
        <div class="dash-empty-state">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <p><?= e(t('landing.alerts.empty')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap alt-table-wrap">
            <table class="data-table alt-table">
                <thead>
                    <tr>
                        <th style="width:52px">#</th>
                        <th style="width:100px"><?= e(t('common.fields.priority')) ?></th>
                        <th><?= e(t('dashboard.apartment_meter')) ?></th>
                        <th style="width:82px"><?= e(t('common.fields.period')) ?></th>
                        <th style="width:80px"><?= e(t('common.fields.utility')) ?></th>
                        <th class="num" style="width:80px"><?= e(t('common.fields.value')) ?></th>
                        <th class="num" style="width:90px"><?= e(t('common.fields.change_pct')) ?></th>
                        <th><?= e(t('common.fields.source')) ?></th>
                        <th style="width:140px"><?= e(t('common.fields.status')) ?></th>
                        <th class="num" style="width:70px"><?= e(t('ui.days_open')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $a): ?>
                    <tr class="alt-row">
                        <td class="alt-td-id">
                            <a href="<?= e(base_url('/alerts/' . $a['id'])) ?>">#<?= (int) $a['id'] ?></a>
                        </td>
                        <td><?= alert_priority_badge($a['priority']) ?></td>
                        <td class="alt-td-apt">
                            <?= e($a['building_code'] . ' / ' . $a['apartment_code']) ?>
                            <?= $a['meter_code'] ? '<span class="alt-meter-code"> / ' . e($a['meter_code']) . '</span>' : '' ?>
                        </td>
                        <td class="alt-td-period"><?= e($a['period_code']) ?></td>
                        <td class="alt-td-utility"><?= e(\App\Services\NoUsageConsumptionService::utilityLabel($a)) ?></td>
                        <td class="num alt-td-num">
                            <?= $a['actual_value'] === null ? '<span class="alt-dash">—</span>' : e(format_decimal($a['actual_value'])) ?>
                        </td>
                        <td class="num"><?= $pctBadge($a['percent_change']) ?></td>
                        <td class="alt-td-source">
                            <span class="alt-source-label"><?= e(config_labels('alerts.source')[$a['source']]) ?></span>
                            <?php if ($a['rule_name']): ?>
                                <div class="alt-rule-name"><?= e($a['rule_name']) ?></div>
                            <?php endif; ?>
                            <?php if (\App\Services\NoUsageConsumptionService::isJ16($a)): ?>
                                <div class="alt-rule-name">
                                    <?= e(label_t('j16.label', (string) config('j16.label'))) ?>
                                    <span class="badge badge--warning" style="font-size:10px;padding:1px 5px;margin-left:3px"><?= e(t('guide.unused_badge')) ?></span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= alert_status_badge($a['status']) ?></td>
                        <td class="num alt-td-num"><?= (int) $a['days_open'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
