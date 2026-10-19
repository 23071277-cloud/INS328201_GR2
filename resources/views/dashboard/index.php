<?php
$user = auth_user();
$role = $user['role'] ?? '';
$sessionTimeout = (string) \App\Services\SystemParameterService::get('session_timeout_minutes');
$maxFailedLogin = (string) \App\Services\SystemParameterService::get('max_failed_login_attempts');

$roleLabels = [
    'bql'      => ['label' => 'Ban Quản Lý', 'icon' => '🏢', 'color' => 'primary'],
    'ke_toan'  => ['label' => 'Kế Toán',     'icon' => '💼', 'color' => 'success'],
    'ky_thuat' => ['label' => 'Kỹ Thuật',    'icon' => '🔧', 'color' => 'warning'],
];
$roleMeta = $roleLabels[$role] ?? ['label' => ucfirst($role), 'icon' => '👤', 'color' => 'info'];

$quickLinks = [];
if (can('buildings.view'))           $quickLinks[] = ['icon' => '🏢', 'label' => 'Tòa nhà',       'url' => base_url('/buildings'),         'desc' => 'Quản lý danh sách tòa nhà'];
if (can('apartments.view'))          $quickLinks[] = ['icon' => '🏠', 'label' => 'Căn hộ',         'url' => base_url('/apartments'),        'desc' => 'Xem & quản lý căn hộ'];
if (can('meters.view'))              $quickLinks[] = ['icon' => '📟', 'label' => 'Công tơ',         'url' => base_url('/meters'),            'desc' => 'Công tơ điện & nước'];
if (can('billing_periods.view'))     $quickLinks[] = ['icon' => '🗓️', 'label' => 'Kỳ ghi chỉ số', 'url' => base_url('/periods'),           'desc' => 'Mở / chốt kỳ thu'];
if (can('billing.view'))             $quickLinks[] = ['icon' => '🧮', 'label' => 'Tính phí',        'url' => base_url('/periods'),           'desc' => 'Tính phí & xác nhận hóa đơn'];
if (can('alerts.view'))              $quickLinks[] = ['icon' => '🚨', 'label' => 'Cảnh báo',        'url' => base_url('/alerts'),            'desc' => 'Cảnh báo tiêu thụ bất thường'];
if (can('reports.view'))             $quickLinks[] = ['icon' => '📑', 'label' => 'Báo cáo',         'url' => base_url('/reports/utility'),   'desc' => 'Báo cáo tiêu thụ & chi phí'];
if (can('users.view'))               $quickLinks[] = ['icon' => '👤', 'label' => 'Người dùng',      'url' => base_url('/users'),             'desc' => 'Quản lý tài khoản hệ thống'];
if (can('email_notifications.view')) $quickLinks[] = ['icon' => '✉️', 'label' => 'Thông báo email', 'url' => base_url('/email-notifications'),'desc' => 'Gửi & theo dõi email'];
if (can('payments.view'))            $quickLinks[] = ['icon' => '💳', 'label' => 'Thanh toán',      'url' => base_url('/payments'),          'desc' => 'Tra cứu giao dịch thanh toán'];
?>

<!-- Welcome Banner -->
<div class="dash-welcome">
    <div class="dash-welcome__body">
        <div class="dash-welcome__avatar"><?= $roleMeta['icon'] ?></div>
        <div>
            <h1 class="dash-welcome__name">Xin chào, <?= e($user['full_name']) ?> 👋</h1>
            <p class="dash-welcome__sub">
                <span class="badge badge--role"><?= e($roleMeta['label']) ?></span>
                <span class="dash-welcome__hint">Hệ thống Quản lý Điện &amp; Nước — Energy &amp; Utility DSS</span>
            </p>
        </div>
    </div>
    <div class="dash-welcome__stats">
        <div class="dash-welcome__stat">
            <span class="dash-welcome__stat-icon">⏱️</span>
            <div>
                <div class="dash-welcome__stat-val"><?= e($sessionTimeout) ?> phút</div>
                <div class="dash-welcome__stat-lbl">Hết phiên khi không hoạt động</div>
            </div>
        </div>
        <div class="dash-welcome__stat">
            <span class="dash-welcome__stat-icon">🔒</span>
            <div>
                <div class="dash-welcome__stat-val"><?= e($maxFailedLogin) ?> lần</div>
                <div class="dash-welcome__stat-lbl">Khoá tạm sau số lần đăng nhập sai</div>
            </div>
        </div>
        <div class="dash-welcome__stat">
            <span class="dash-welcome__stat-icon">✅</span>
            <div>
                <div class="dash-welcome__stat-val">Hoạt động</div>
                <div class="dash-welcome__stat-lbl">Trạng thái hệ thống</div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Access -->
<?php if ($quickLinks !== []): ?>
<div class="dash-section">
    <h2 class="dash-section__title">
        <span class="dash-section__icon">⚡</span> Truy cập nhanh
    </h2>
    <div class="dash-quick-grid">
        <?php foreach ($quickLinks as $ql): ?>
        <a href="<?= e($ql['url']) ?>" class="dash-quick-card">
            <span class="dash-quick-card__icon"><?= $ql['icon'] ?></span>
            <div class="dash-quick-card__body">
                <div class="dash-quick-card__label"><?= e($ql['label']) ?></div>
                <div class="dash-quick-card__desc"><?= e($ql['desc']) ?></div>
            </div>
            <svg class="dash-quick-card__arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Go to full dashboard -->
<?php if (can('reports.view')): ?>
<div class="dash-section">
    <h2 class="dash-section__title">
        <span class="dash-section__icon">📊</span> Bảng điều khiển phân tích
    </h2>
    <div class="dash-cta-banner">
        <div class="dash-cta-banner__text">
            <strong>Dashboard phân tích tiêu thụ</strong>
            <span>Xem xu hướng tiêu thụ điện &amp; nước, cảnh báo vượt ngưỡng và so sánh baseline theo kỳ.</span>
        </div>
        <a href="<?= e(base_url('/dashboard/decision')) ?>" class="btn btn--primary">Xem Dashboard →</a>
    </div>
</div>
<?php endif; ?>
