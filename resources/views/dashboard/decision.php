<?php
/** @var array $d DashboardService::build @var array $options */
$f = $d['filters'];
$p = $d['period'];
$units = config('pricing.utility_unit');
$uLabel = config_labels('alerts.utility_word');
$unknown = label_t('alerts.unknown_label', (string) config('alerts.unknown_label', 'Không xác định'));
$uText = static fn (?string $u): string => (string) (($u !== null ? $uLabel[$u] ?? null : null) ?? $unknown);
$sel = function (string $name, array $opts, string $current, string $all = ''): string {
    $html = '<select id="' . e($name) . '" name="' . e($name) . '">' . ($all !== '' ? '<option value="">' . e($all) . '</option>' : '');
    foreach ($opts as $v => $l) {
        $html .= '<option value="' . e((string) $v) . '"' . ((string) $v === $current ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $html . '</select>';
};
$pctBox = static fn (?string $pct, ?string $prev) => $prev === null ? '—' : pct_text($pct);
?>

<link rel="stylesheet" href="<?= e(asset('css/dashboard.css')) ?>">
<div class="decision-dashboard">
<!-- Page Header -->
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.dashboard')) ?></h1>
        <p class="dashboard-subtitle"><?= t('dashboard.subtitle_html') ?></p>
    </div>
    <div class="page-head__actions no-print">
        <?php if (can('reports.view')): ?>
            <a class="btn btn--ghost" href="<?= e(url_with('/reports/utility', ['period_id' => $f['period_id'], 'building_id' => $f['building_id'], 'zone_id' => $f['zone_id']])) ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <?= e(t('menu.reports')) ?>
            </a>
        <?php endif; ?>
        <?php if (can('alerts.view')): ?>
            <a class="btn btn--ghost" href="<?= e(url_with('/alerts', ['period_id' => $f['period_id']])) ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?= e(t('crumbs.alert_list')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filter Bar -->
<div class="panel dash-filter-panel no-print">
    <form method="get" action="<?= e(base_url('/dashboard')) ?>" class="dash-filter-bar">
        <div class="dash-filter-bar__fields">
            <div class="form-group">
                <label for="period_id"><?= e(t('common.fields.period')) ?></label>
                <?= $sel('period_id', array_column($options['periods'], 'period_code', 'id'), $f['period_id']) ?>
            </div>
            <div class="form-group">
                <label for="building_id"><?= e(t('common.fields.building')) ?></label>
                <?= $sel('building_id', array_column($options['buildings'], 'code', 'id'), $f['building_id']) ?>
            </div>
            <div class="form-group">
                <label for="zone_id"><?= e(t('dashboard.zone')) ?></label>
                <?= $sel('zone_id', array_column($options['zones'], 'name', 'id'), $f['zone_id'], t('dashboard.whole_building')) ?>
            </div>
            <div class="form-group">
                <label for="utility_type"><?= e(t('dashboard.utility_table')) ?></label>
                <?= $sel('utility_type', master_labels('utility_type'), $f['utility_type']) ?>
            </div>
        </div>
        <button type="submit" class="btn btn--primary dash-filter-bar__btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <?= e(t('common.fields.search')) ?>
        </button>
    </form>
    <?php if ($f['zone_id'] !== ''): ?>
        <p class="form-hint" style="margin:8px 0 0"><?= t('dashboard.zone_hint_html') ?></p>
    <?php endif; ?>
</div>

<?php if ($p === null || !$d['has_data']): ?>
    <div class="dash-empty-period">
        <div class="dash-empty-period__icon">📭</div>
        <p><?= e(t('landing.dashboard.empty_period')) ?></p>
    </div>
<?php endif; ?>

<?php if ($p !== null): ?>

<!-- =====================================================================
     VIỆC CẦN LÀM: thứ người dùng muốn biết đầu tiên - hôm nay mình phải làm gì
     ===================================================================== -->
<?php
$ic = \App\Services\IssueCounterService::forCurrentUser();
$todo = [];
if (($ic['periods'] ?? 0) > 0) {
    if (can('meter_readings.approve')) { $todo[] = ['periods', t('dashboard.todo.approve_readings', ['n' => $ic['periods']]), '/periods', 'warning']; }
    elseif (can('meter_readings.create')) { $todo[] = ['periods', t('dashboard.todo.enter_readings', ['n' => $ic['periods']]), '/periods', 'warning']; }
}
if (($ic['billing'] ?? 0) > 0 && can('billing.confirm')) { $todo[] = ['billing', t('dashboard.todo.confirm_bills', ['n' => $ic['billing']]), '/periods', 'warning']; }
if (($ic['alerts'] ?? 0) > 0) {
    if (can('alerts.review')) { $todo[] = ['alerts', t('dashboard.todo.review_alerts', ['n' => $ic['alerts']]), '/alerts', 'danger']; }
    elseif (can('alerts.update_verification')) { $todo[] = ['alerts', t('dashboard.todo.assigned_cases', ['n' => $ic['alerts']]), '/alerts', 'danger']; }
}
if (($ic['emails'] ?? 0) > 0 && can('email_notifications.send')) { $todo[] = ['emails', t('dashboard.todo.failed_emails', ['n' => $ic['emails']]), '/email-notifications', 'danger']; }
?>
<section class="dash-todo no-print" aria-labelledby="dashTodoTitle">
    <div class="dash-todo__head">
        <h2 class="dash-todo__title" id="dashTodoTitle"><?= e(t('dashboard.todo.title')) ?></h2>
        <a class="dash-todo__help" href="<?= e(base_url('/help')) ?>"><?= e(t('a11y.help_title')) ?> →</a>
    </div>
    <?php if ($todo === []): ?>
        <p class="dash-todo__empty"><span aria-hidden="true">✓</span> <?= e(t('dashboard.todo.empty')) ?></p>
    <?php else: ?>
        <div class="dash-todo__list">
            <?php foreach ($todo as [$tk, $tl, $tp, $tone]): ?>
                <a class="dash-todo__item dash-todo__item--<?= e($tone) ?>" href="<?= e(base_url($tp)) ?>">
                    <span class="dash-todo__text"><?= e($tl) ?></span>
                    <span class="dash-todo__go" aria-hidden="true">→</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>


<!-- =====================================================================
     KPI CARDS — Hàng 1: Điện | Nước | Tổng chi phí | Cảnh báo
     Hàng 2: Vượt baseline | Mục tiêu | Khuyến nghị (3 cột cân đối)
     ===================================================================== -->
<div class="kpi-grid-row kpi-row-4">
    <?php foreach (['dien', 'nuoc'] as $u):
        $c = $d['consumption'][$u] ?? null;
        $isElec = $u === 'dien';
    ?>
    <div class="kpi-card kpi-card--<?= $isElec ? 'electric' : 'water' ?>">
        <div class="kpi-card__header">
            <span class="kpi-card__label"><?= e(t('dashboard.total_consumption', ['utility' => $uText($u), 'unit' => $units[$u] ?? ''])) ?></span>
            <span class="kpi-card__icon" aria-hidden="true">
                <?php if ($isElec): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>
                <?php else: ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6 10 4 14 4 16a8 8 0 0 0 16 0c0-2-2-6-8-14z"/></svg>
                <?php endif; ?>
            </span>
        </div>
        <div class="kpi-card__value">
            <?= $c === null || $c['current_value'] === null ? '—' : e(format_decimal($c['current_value'])) ?>
            <?php if ($c !== null && $c['current_value'] !== null): ?>
                <span class="kpi-card__unit"><?= e($units[$u] ?? '') ?></span>
            <?php endif; ?>
        </div>
        <div class="kpi-card__footer">
            <?php if ($c !== null && $c['current_value'] === null): ?>
                <span class="kpi-card__hint"><?= e(config_labels('analytics.data_status')[$c['data_status']] ?? '') ?></span>
            <?php elseif ($c !== null): ?>
                <span class="kpi-card__hint">
                    <?= e(t('dashboard.vs_previous')) ?> <strong><?= e($pctBox($c['previous_percentage_change'], $c['previous_value'])) ?></strong>
                    <?= e(trend_text($c['previous_difference'] === null ? null : (bccomp($c['previous_difference'], '0', 2) > 0 ? 'up' : (bccomp($c['previous_difference'], '0', 2) < 0 ? 'down' : 'equal')))) ?>
                    <?php if (can('comparisons.view')): ?>
                        · <?= e(t('dashboard.vs_baseline')) ?> <?= e($c['baseline_value'] === null ? t('dashboard.no_baseline') : pct_text($c['percentage_change'])) ?>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Chi phí -->
    <?php if (isset($d['cost'])): $cost = $d['cost']; ?>
    <div class="kpi-card kpi-card--cost">
        <div class="kpi-card__header">
            <span class="kpi-card__label"><?= e(t('dashboard.utility_cost')) ?></span>
            <span class="kpi-card__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </span>
        </div>
        <div class="kpi-card__value">
            <?= e(money_vnd(\App\Core\Decimal::fromCents(\App\Services\MoneyCalculator::fromDb($cost['electricity_cost']) + \App\Services\MoneyCalculator::fromDb($cost['water_cost'])))) ?>
        </div>
        <div class="kpi-card__footer">
            <span class="kpi-card__hint">
                <?= $cost['official'] ? e(t('dashboard.official')) : t('dashboard.provisional_html') ?>
                · <?= e(t('dashboard.confirmed_bills', ['confirmed' => (int) $cost['confirmed'], 'charges' => (int) $cost['charges'], 'amount' => money_vnd($cost['total_arising'])])) ?>
            </span>
        </div>
    </div>
    <?php else: ?>
    <div class="kpi-card kpi-card--cost kpi-card--empty">
        <div class="kpi-card__header">
            <span class="kpi-card__label"><?= e(t('dashboard.utility_cost')) ?></span>
            <span class="kpi-card__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </span>
        </div>
        <div class="kpi-card__value">—</div>
        <div class="kpi-card__footer"><span class="kpi-card__hint"><?= e(config_labels('analytics.data_status')['no_data']) ?></span></div>
    </div>
    <?php endif; ?>

    <!-- Cảnh báo -->
    <?php if (isset($d['alerts'])): ?>
    <div class="kpi-card kpi-card--alert">
        <div class="kpi-card__header">
            <span class="kpi-card__label">
                <?= e(t('dashboard.open_alerts')) ?><?= (auth_user()['role'] ?? '') === 'ky_thuat' ? e(t('dashboard.mine')) : '' ?>
            </span>
            <span class="kpi-card__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </span>
        </div>
        <div class="kpi-card__value"><?= (int) $d['alerts']['open'] ?></div>
        <div class="kpi-card__footer">
            <span class="kpi-card__hint"><?= t('dashboard.alert_counts_html', ['high' => (int) $d['alerts']['high'], 'recheck' => (int) $d['alerts']['recheck']]) ?></span>
            <?php if (($d['alerts']['j16'] ?? 0) > 0): ?>
                <span class="kpi-card__hint">
                    <a href="<?= e(url_with('/alerts', ['alert_type' => config('j16.type')])) ?>"><?= e(label_t('j16.dashboard_label', (string) config('j16.dashboard_label'))) ?>: <strong><?= (int) $d['alerts']['j16'] ?></strong></a>
                    <span class="badge badge--warning" style="font-size:10px;padding:1px 5px"><?= e(t('guide.unused_badge')) ?></span>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="kpi-card kpi-card--alert kpi-card--empty">
        <div class="kpi-card__header">
            <span class="kpi-card__label"><?= e(t('dashboard.open_alerts')) ?></span>
            <span class="kpi-card__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </span>
        </div>
        <div class="kpi-card__value">—</div>
        <div class="kpi-card__footer"><span class="kpi-card__hint"><?= e(t('ui.access_restricted')) ?></span></div>
    </div>
    <?php endif; ?>
</div>

<!-- =====================================================================
     XU HƯỚNG: 2 biểu đồ cột
     ===================================================================== -->
<div class="card-grid">
    <?php foreach (['dien' => '#f97316', 'nuoc' => '#0891b2'] as $u => $color): ?>
        <div class="panel panel--<?= $u === 'dien' ? 'electric' : 'water' ?>">
            <div class="panel__head">
                <h2 class="panel__title"><?= e(t('dashboard.trend', ['utility' => $uText($u), 'n' => count($d['trend'][$u] ?? [])])) ?></h2>
            </div>
            <?= svg_bars($d['trend'][$u] ?? [], $units[$u] ?? '', t('dashboard.trend_svg', ['utility' => $uText($u)]), $color) ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- =====================================================================
     CẢNH BÁO CẦN XỬ LÝ
     ===================================================================== -->
<?php if (isset($d['alerts'])): ?>
<div class="panel">
    <div class="panel__head">
        <h2 class="panel__title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <?= e(t('dashboard.alerts_to_handle')) ?>
        </h2>
    </div>
    <?php if ($d['alerts']['top'] === []): ?>
        <div class="dash-empty-state">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <p><?= e(t('landing.dashboard.empty_alerts')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?= e(t('common.fields.priority')) ?></th>
                        <th><?= e(t('dashboard.apartment_meter')) ?></th>
                        <th><?= e(t('common.fields.utility')) ?></th>
                        <th class="num"><?= e(t('common.fields.change_pct')) ?></th>
                        <th><?= e(t('common.fields.status')) ?></th>
                        <th class="num"><?= e(t('dashboard.days_open')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($d['alerts']['top'] as $a): ?>
                        <tr>
                            <td><a href="<?= e(base_url('/alerts/' . $a['id'])) ?>">#<?= (int) $a['id'] ?></a></td>
                            <td><?= alert_priority_badge($a['priority']) ?></td>
                            <td><?= e($a['apartment_code']) ?><?= $a['meter_code'] ? ' / ' . e($a['meter_code']) : '' ?></td>
                            <td><?= e(alert_utility_text($a)) ?></td>
                            <td class="num"><?= $a['percent_change'] === null ? '—' : e(pct_text($a['percent_change'])) ?></td>
                            <td><?= alert_status_badge($a['status']) ?></td>
                            <td class="num"><?= (int) $a['days_open'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-hint" style="margin-top:10px">
            <?= e(t('dashboard.by_status')) ?>
            <?php foreach ($d['alerts']['by']['status'] as $s => $n): ?>
                <?= alert_status_badge($s) ?> <?= (int) $n ?> &nbsp;
            <?php endforeach; ?>
            <?= e(t('dashboard.open_by_utility')) ?>
            <?php foreach ($d['alerts']['by']['utility'] as $u => $n): ?>
                <?= e(config_labels('alerts.utility_label')[$u] ?? $unknown) ?> <?= (int) $n ?> &nbsp;
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- =====================================================================
     TOP CONSUMERS + BASELINE TOP INCREASE
     ===================================================================== -->
<div class="card-grid">
    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                <?= e(t('dashboard.top_consumers', ['utility' => $uText($f['utility_type'])])) ?>
            </h2>
        </div>
        <?php if (($d['top_consumers'] ?? []) === []): ?>
            <div class="dash-empty-state">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <p><?= e(t('landing.dashboard.empty_data')) ?></p>
            </div>
        <?php else: ?>
            <?php $consumerMax = max(array_column($d['top_consumers'], 'value')); ?>
            <ol class="consumer-list consumer-list--<?= $f['utility_type'] === 'dien' ? 'electric' : 'water' ?>">
                <?php foreach ($d['top_consumers'] as $rank => $tc): ?>
                    <li class="consumer-row<?= $rank === 0 ? ' is-highest' : '' ?>">
                        <div class="consumer-row__head">
                            <span><span class="consumer-rank"><?= $rank + 1 ?></span><?= e($tc['apartment']['code']) ?></span>
                            <strong><?= e(format_decimal(\App\Core\Decimal::fromCents($tc['value']))) ?> <small><?= e($units[$f['utility_type']] ?? '') ?></small></strong>
                        </div>
                        <div class="dashboard-meter" aria-hidden="true"><span style="width:<?= $consumerMax > 0 ? max(0, min(100, $tc['value'] / $consumerMax * 100)) : 0 ?>%"></span></div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>

    <?php if (isset($d['baseline']) && $d['baseline']['top_increase'] !== []): ?>
    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px" aria-hidden="true"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                <?= e(t('dashboard.top_vs_baseline', ['utility' => $uText($f['utility_type'])])) ?>
            </h2>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= e(t('dashboard.apartment')) ?></th>
                        <th class="num"><?= e(t('common.fields.current')) ?></th>
                        <th class="num"><?= e(t('common.fields.baseline')) ?></th>
                        <th class="num"><?= e(t('common.fields.difference')) ?></th>
                        <th class="num"><?= e(t('common.fields.change_pct')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($d['baseline']['top_increase'] as $r): ?>
                        <tr>
                            <td><?= e($r['scope_label']) ?></td>
                            <td class="num"><?= e(format_decimal($r['current_value'])) ?></td>
                            <td class="num"><?= e(format_decimal($r['baseline_value'])) ?></td>
                            <td class="num"><?= e(format_decimal($r['difference'])) ?></td>
                            <td class="num"><?= e(pct_text($r['percentage_change'])) ?> ▲</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- =====================================================================
     PHÂN TÍCH THÊM: so sánh mức chuẩn, mục tiêu, gợi ý - xem sau khi đã nắm số chính
     ===================================================================== -->
<h2 class="dash-section-title"><?= e(t('dashboard.analysis_title')) ?></h2>
<!-- Hàng 2: Baseline | Mục tiêu | Khuyến nghị (3 thẻ cân đối) -->
<?php
$row2Cards = [];
if (isset($d['baseline']))       $row2Cards[] = 'baseline';
if (isset($d['targets']))        $row2Cards[] = 'targets';
if (isset($d['recommendations'])) $row2Cards[] = 'recommendations';
?>
<?php if ($row2Cards !== []): ?>
<div class="kpi-grid-row kpi-row-3">
    <?php if (isset($d['baseline'])): ?>
    <div class="kpi-card kpi-card--baseline">
        <div class="kpi-card__header">
            <span class="kpi-card__label"><?= e(t('dashboard.above_baseline', ['utility' => $uText($f['utility_type'])])) ?></span>
            <span class="kpi-card__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            </span>
        </div>
        <div class="kpi-card__value">
            <?= (int) $d['baseline']['above'] ?><span class="kpi-card__unit"> / <?= (int) $d['baseline']['compared'] ?></span>
        </div>
        <div class="kpi-card__footer">
            <span class="kpi-card__hint"><?= e(t('dashboard.comparable', ['n' => (int) $d['baseline']['no_baseline']])) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($d['targets'])): $tgt = $d['targets']; ?>
    <div class="kpi-card kpi-card--target">
        <div class="kpi-card__header">
            <span class="kpi-card__label"><?= e(t('dashboard.targets')) ?></span>
            <span class="kpi-card__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
            </span>
        </div>
        <div class="kpi-card__value">
            <?= e($tgt['rate'] === null ? 'N/A' : format_decimal($tgt['rate']) . '%') ?>
        </div>
        <div class="kpi-card__footer">
            <span class="kpi-card__hint">
                ✔ <?= e(config_label('analytics.target_status', 'dat')) ?> <?= (int) $tgt['dat'] ?>
                &nbsp;·&nbsp;
                ✘ <?= e(config_label('analytics.target_status', 'khong_dat')) ?> <?= (int) $tgt['khong_dat'] ?>
                &nbsp;·&nbsp;
                … <?= e(config_label('analytics.target_status', 'chua_du_du_lieu')) ?> <?= (int) $tgt['chua_du_du_lieu'] ?>
            </span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($d['recommendations'])): ?>
    <div class="kpi-card kpi-card--reco">
        <div class="kpi-card__header">
            <span class="kpi-card__label"><?= e(t('dashboard.recommendations')) ?></span>
            <span class="kpi-card__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </span>
        </div>
        <div class="kpi-card__value"><?= (int) $d['recommendations']['total'] ?></div>
        <div class="kpi-card__footer">
            <span class="kpi-card__hint"><?= e(t('dashboard.of_alerts', ['n' => (int) $d['recommendations']['alerts']])) ?></span>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- =====================================================================
     MỤC TIÊU + KHUYẾN NGHỊ
     ===================================================================== -->
<div class="card-grid">
    <?php if (isset($d['targets'])): ?>
    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                <?= e(t('dashboard.target_status')) ?>
            </h2>
        </div>
        <?php if ($d['targets']['rows'] === []): ?>
            <div class="dash-empty-state">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                <p><?= e(t('landing.dashboard.empty_targets')) ?></p>
            </div>
        <?php else: ?>
            <div class="target-list">
                <?php foreach ($d['targets']['rows'] as $tRow):
                    $tracking = $tRow['tracking'];
                    $targetPct = $tracking['achievement_percentage'] ?? null;
                    $variance = $tracking['variance'] ?? null;
                    $targetUnit = $units[$tRow['utility_type']] ?? '';
                ?>
                    <article class="target-item target-item--<?= e($tracking['target_status']) ?>">
                        <div class="target-item__head">
                            <strong><?= e(config_labels('analytics.scope_type')[$tRow['scope_type']] ?? $unknown) ?> <?= e($tRow['scope_label']) ?> · <?= e($uText($tRow['utility_type'] ?? null)) ?></strong>
                            <?= target_badge($tracking['target_status']) ?>
                        </div>
                        <div class="target-item__values">
                            <span><?= $tracking['actual_value'] === null ? '?' : e(format_decimal($tracking['actual_value'])) ?> / <?= e(format_decimal($tracking['target_value'])) ?> <?= e($targetUnit) ?></span>
                            <strong><?= $targetPct === null ? '?' : e(format_decimal($targetPct)) . '%' ?></strong>
                        </div>
                        <?php if ($targetPct !== null): ?>
                            <div class="dashboard-meter" aria-hidden="true"><span style="width:<?= max(0, min(100, (float) $targetPct)) ?>%"></span></div>
                        <?php endif; ?>
                        <?php if ($variance !== null): ?>
                            <p class="target-item__variance"><?= e(t((float) $variance > 0 ? 'ui.target_exceeded' : 'ui.target_remaining')) ?>: <?= e(format_decimal(ltrim($variance, '-'))) ?> <?= e($targetUnit) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (isset($d['recommendations'])): ?>
    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= e(t('dashboard.recent_recommendations')) ?>
            </h2>
        </div>
        <?php if ($d['recommendations']['recent'] === []): ?>
            <div class="dash-empty-state">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <p><?= e(t('landing.dashboard.empty_recommendations')) ?></p>
            </div>
        <?php else: ?>
            <ul class="advisory-list">
                <?php foreach ($d['recommendations']['recent'] as $r): ?>
                    <li>
                        <a href="<?= e(base_url('/alerts/' . $r['alert_id'])) ?>"><?= e(t('alert_show.heading', ['id' => (int) $r['alert_id']])) ?></a>
                        <span class="advisory-apartment"><?= e($r['apartment_code']) ?></span>
                        <?php if (isset($r['status'])): ?><?= alert_status_badge($r['status']) ?><?php endif; ?>
                        <?php if (isset($r['created_at'])): ?><time class="advisory-date"><?= e(format_datetime($r['created_at'])) ?></time><?php endif; ?>
                        <p><?= e($r['recommendation_text']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>


<?php endif; // $p !== null ?>

</div>
