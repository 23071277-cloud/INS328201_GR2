<?php
// Badge "việc cần xử lý" theo quyền (IssueCounterService). Luôn render thẻ (ẩn khi 0) để JS cập nhật sau thao tác không tải lại trang.
$issueCounts = \App\Services\IssueCounterService::forCurrentUser();
$issueTitles = [
    'alerts' => can('alerts.review') ? t('issues.alerts_review') : t('issues.alerts_assigned'),
    'periods' => can('meter_readings.approve') ? t('issues.periods_approve') : t('issues.periods_record'),
    'billing' => t('issues.billing'),
    'emails' => t('issues.emails'),
];
$issueBadge = static function (string $key) use ($issueCounts, $issueTitles): string {
    $n = (int) ($issueCounts[$key] ?? 0);

    return '<span class="nav-badge" data-issue-count="' . e($key) . '"' . ($n > 0 ? '' : ' hidden') . ' title="' . e($issueTitles[$key] ?? '') . '">'
        . '<span aria-hidden="true">' . $n . '</span><span class="sr-only"> ' . e(t('issues.count', ['n' => $n, 'title' => $issueTitles[$key] ?? ''])) . '</span></span>';
};
?>
<aside class="app-sidebar" id="appSidebar">
    <div class="app-sidebar__brand"><?= e(config('app.name')) ?></div>

    <nav class="app-sidebar__nav">
        <?php if (can('billing_adjustments.view')): ?><a href="<?= e(base_url('/billing-adjustments')) ?>" class="app-sidebar__link"><span class="app-sidebar__icon">🧾</span> <?= e(t('menu.adjustments')) ?></a><?php endif; ?>
        <a href="<?= e(base_url('/dashboard')) ?>" class="app-sidebar__link">
            <span class="app-sidebar__icon">📊</span> <?= e(t('menu.dashboard')) ?>
        </a>

        <?php
        // Sidebar riêng theo vai trò: chỉ nhóm lại / đổi nhãn menu theo việc của từng vai trò. CHỈ là hiển thị - route / quyền backend
        // (RbacMiddleware) không đổi, mục vẫn lọc bằng can(); trang không có trong menu vẫn mở được bằng URL nếu role có quyền.
        // Mọi vai trò: nhóm thu gọn / mở rộng (chỉ mở sẵn nhóm chứa trang hiện tại - server render, không nhảy khi tải lại).
        $issueCount = static fn (string $key, bool $shown): int => $shown ? (int) ($issueCounts[$key] ?? 0) : 0;
        $roleKey = auth_user()['role'] ?? '';
        $rolePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // [quyền, đường dẫn, icon, nhãn, regex trang đang mở, phần đuôi (badge), số việc cần xử lý (cộng vào tổng của nhóm khi thu gọn), title]
        $roleMenu = null;
        if ($roleKey === 'ke_toan') {
            // Kế toán: ẩn Tòa nhà, Khu vực, Công tơ, Kỳ ghi chỉ số, Mục tiêu, Cảnh báo, Quy tắc ngưỡng...
            $ktPayments = can('payments.view') && (new \App\Services\PaymentStatusService())->available();
            $ktReview = $ktPayments ? (new \App\Services\PaymentStatusService())->countOpenReview() : 0;
            $roleMenu = [
                t('menu.groups.billing') => [
                    ['billing.view', '/periods', '🧮', t('menu.billing'), '#/(periods|billing|charges|cost-allocation|imports)(/|$)#', can('billing.confirm') ? $issueBadge('billing') : '', $issueCount('billing', can('billing.confirm'))],
                ],
                t('menu.groups.payments') => $ktPayments ? [
                    ['payments.view', '/payments', '💳', t('menu.payments'), '#/payments$#', ''],
                    ['payments.view', '/payments/review', '🔎', t('menu.payment_review'), '#/payments/review#', $ktReview > 0 ? ' <span class="badge badge--danger" data-payment-review-count>' . $ktReview . '</span>' : '', $ktReview],
                ] : [],
                t('menu.groups.notifications') => [
                    ['email_notifications.view', '/email-notifications', '✉️', t('menu.outbox'), '#/email-notifications(/|$)#', can('email_notifications.send') ? $issueBadge('emails') : '', $issueCount('emails', can('email_notifications.send'))],
                    ['zalo_notifications.view', '/zalo-notifications', '💬', t('zalo.title'), '#/zalo-notifications(/|$)#', ''],
                ],
                t('menu.groups.reports') => [
                    ['reports.view', '/reports/utility', '📑', t('menu.reports'), '#/reports(/|$)#', ''],
                ],
                t('menu.groups.pricing') => [
                    ['tariffs.view', '/tariffs', '💰', t('menu.tariffs'), '#/tariffs(/|$)#', ''],
                    ['fees.view', '/fees', '🧾', t('menu.fees'), '#/fees(/|$)#', ''],
                    ['tariff_proposals.view', '/tariff-proposals', '📝', t('menu.tariff_proposals'), '#/tariff-proposals(/|$)#', ''],
                ],
                t('menu.groups.lookup') => [
                    ['apartments.view', '/apartments', '🏠', t('menu.apartments'), '#/apartments(/|$)#', ''],
                    ['meter_readings.view', '/readings', '🔎', t('menu.readings'), '#/readings(/|$)#', ''],
                ],
            ];
        } elseif ($roleKey === 'ky_thuat') {
            // Kỹ thuật: gộp "Baseline & mục tiêu" + "Cảnh báo & khuyến nghị" thành "Theo dõi & phân tích"; mục / icon / badge / highlight như cũ.
            $roleMenu = [
                t('menu.groups.catalog') => [
                    ['buildings.view', '/buildings', '🏢', t('menu.buildings'), '#/buildings(/|$)#', ''],
                    ['zones.view', '/zones', '🗺️', t('menu.zones'), '#/zones(/|$)#', ''],
                    ['apartments.view', '/apartments', '🏠', t('menu.apartments'), '#/apartments(/|$)#', ''],
                    ['meters.view', '/meters', '📟', t('menu.meters'), '#/meters(/|$)#', ''],
                ],
                t('menu.groups.readings') => [
                    ['billing_periods.view', '/periods', '🗓️', t('menu.periods'), '#/periods(/|$)|/imports/#', can('meter_readings.approve') || can('meter_readings.create') ? $issueBadge('periods') : '', $issueCount('periods', can('meter_readings.approve') || can('meter_readings.create'))],
                    ['meter_readings.view', '/readings', '🔎', t('menu.readings'), '#/readings(/|$)#', ''],
                ],
                t('menu.groups.analytics') => [
                    ['targets.view', '/targets', '🎯', t('menu.targets'), '#/targets(/|$)#', ''],
                    ['alerts.view', '/alerts', '🚨', t('menu.alerts'), '#/alerts(/|$)#', can('alerts.review') || can('alerts.update_verification') ? $issueBadge('alerts') : '', $issueCount('alerts', can('alerts.review') || can('alerts.update_verification'))],
                    ['alert_rules.view', '/alert-rules', '🎚️', t('menu.alert_rules'), '#/alert-rules(/|$)#', ''],
                ],
                t('menu.groups.reports') => [
                    ['reports.view', '/reports/utility', '📑', t('menu.reports'), '#/reports(/|$)#', ''],
                ],
            ];
        } elseif ($roleKey === 'bql') {
            // BQL: đủ chức năng như trước, gộp "Chỉ số công tơ" + "Tính phí & chốt kỳ" = "Vận hành & chỉ số",
            // "Baseline & mục tiêu" + "Cảnh báo & khuyến nghị" = "Theo dõi & phân tích"; mục / icon / badge / highlight như cũ.
            $bqlPayments = can('payments.view') && (new \App\Services\PaymentStatusService())->available();
            $bqlReview = $bqlPayments ? (new \App\Services\PaymentStatusService())->countOpenReview() : 0;
            $periodsBadge = can('meter_readings.approve') || can('meter_readings.create');
            $alertsBadge = can('alerts.review') || can('alerts.update_verification');
            $roleMenu = [
                t('menu.groups.admin') => [
                    ['users.view', '/users', '👤', t('menu.users'), '#/users(/|$)#', ''],
                    ['audit_logs.view', '/audit-logs', '🧾', t('menu.audit_logs'), '#/audit-logs(/|$)#', ''],
                    ['system_parameters.view', '/system-parameters', '⚙️', t('menu.system_parameters'), '#/system-parameters(/|$)#', ''],
                ],
                t('menu.groups.catalog') => [
                    ['buildings.view', '/buildings', '🏢', t('menu.buildings'), '#/buildings(/|$)#', ''],
                    ['zones.view', '/zones', '🗺️', t('menu.zones'), '#/zones(/|$)#', ''],
                    ['apartments.view', '/apartments', '🏠', t('menu.apartments'), '#/apartments(/|$)#', ''],
                    ['meters.view', '/meters', '📟', t('menu.meters'), '#/meters(/|$)#', ''],
                ],
                t('menu.groups.pricing') => [
                    ['tariffs.view', '/tariffs', '💰', t('menu.tariffs'), '#/tariffs(/|$)#', ''],
                    ['fees.view', '/fees', '🧾', t('menu.fees'), '#/fees(/|$)#', ''],
                    ['tariff_proposals.view', '/tariff-proposals', '📝', t('menu.tariff_proposals'), '#/tariff-proposals(/|$)#', ''],
                ],
                t('menu.groups.operations') => [
                    ['billing_periods.view', '/periods', '🗓️', t('menu.periods'), '#/periods(/|$)|/imports/#', $periodsBadge ? $issueBadge('periods') : '', $issueCount('periods', $periodsBadge)],
                    ['meter_readings.view', '/readings', '🔎', t('menu.readings'), '#/readings(/|$)#', ''],
                    ['billing.view', '/periods', '🧮', t('menu.billing'), '#/(billing|charges|cost-allocation)(/|$)#', can('billing.confirm') ? $issueBadge('billing') : '', $issueCount('billing', can('billing.confirm')), t('menu.billing_hint')],
                ],
                t('menu.groups.analytics') => [
                    ['baselines.view', '/baselines', '📏', t('menu.baselines'), '#/baselines(/|$)#', ''],
                    ['comparisons.view', '/comparisons', '📈', t('menu.comparisons'), '#/comparisons(/|$)#', ''],
                    ['targets.view', '/targets', '🎯', t('menu.targets'), '#/targets(/|$)#', ''],
                    ['alerts.view', '/alerts', '🚨', t('menu.alerts'), '#/alerts(/|$)#', $alertsBadge ? $issueBadge('alerts') : '', $issueCount('alerts', $alertsBadge)],
                    ['alert_rules.view', '/alert-rules', '🎚️', t('menu.alert_rules'), '#/alert-rules(/|$)#', ''],
                    ['recommendation_rules.view', '/recommendation-rules', '💡', t('menu.recommendation_rules'), '#/recommendation-rules(/|$)#', ''],
                ],
                t('menu.groups.payments') => $bqlPayments ? [
                    ['payments.view', '/payments', '💳', t('menu.payments'), '#/payments$#', ''],
                    ['payments.view', '/payments/review', '🔎', t('menu.payment_review'), '#/payments/review#', $bqlReview > 0 ? ' <span class="badge badge--danger" data-payment-review-count>' . $bqlReview . '</span>' : '', $bqlReview],
                ] : [],
                t('menu.groups.notifications') => [
                    ['email_notifications.view', '/email-notifications', '✉️', t('menu.outbox'), '#/email-notifications(/|$)#', can('email_notifications.send') ? $issueBadge('emails') : '', $issueCount('emails', can('email_notifications.send'))],
                    ['zalo_notifications.view', '/zalo-notifications', '💬', t('zalo.title'), '#/zalo-notifications(/|$)#', ''],
                ],
                t('menu.groups.reports') => [
                    ['reports.view', '/reports/utility', '📑', t('menu.reports'), '#/reports(/|$)#', ''],
                ],
            ];
        }
        ?>
        <?php $groupNo = 0; ?>
        <?php foreach ((array) $roleMenu as $ktSection => $ktItems): ?>
            <?php $ktItems = array_values(array_filter($ktItems, static fn (array $item): bool => can($item[0]))); ?>
            <?php if ($ktItems !== []): ?>
                <?php
                $groupNo++;
                $groupOpen = array_filter($ktItems, static fn (array $item): bool => (bool) preg_match($item[4], $rolePath)) !== [];
                $groupSum = array_sum(array_map(static fn (array $item): int => (int) ($item[6] ?? 0), $ktItems));
                ?>
                <div class="nav-group<?= $groupOpen ? ' is-open' : '' ?>" data-nav-group>
                    <button type="button" class="nav-group__toggle" aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>" aria-controls="nav-group-<?= $groupNo ?>" data-nav-group-toggle>
                        <span class="nav-group__label"><?= e($ktSection) ?></span>
                        <span class="nav-badge nav-group__sum" data-nav-group-sum title="<?= e(t('menu.group_total')) ?>"<?= $groupSum > 0 ? '' : ' hidden' ?>><?= $groupSum ?></span>
                        <svg class="nav-group__chevron" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div class="nav-group__panel" id="nav-group-<?= $groupNo ?>">
                        <div class="nav-group__inner">
                            <?php foreach ($ktItems as $item): ?>
                                <?php [$permissionKey, $menuPath, $menuIcon, $menuLabel, $activeRe, $tail] = $item; $menuTitle = (string) ($item[7] ?? ''); ?>
                                <a href="<?= e(base_url($menuPath)) ?>" class="app-sidebar__link<?= preg_match($activeRe, $rolePath) ? ' is-active' : '' ?>"<?= $menuTitle !== '' ? ' title="' . e($menuTitle) . '"' : '' ?>>
                                    <span class="app-sidebar__icon"><?= $menuIcon ?></span> <?= e($menuLabel) ?><?= $tail ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</aside>
