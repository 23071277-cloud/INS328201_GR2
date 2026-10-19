<?php

declare(strict_types=1);

use App\Controllers\AlertController;
use App\Controllers\LocaleController;
use App\Controllers\HelpController;
use App\Controllers\AlertRuleController;
use App\Controllers\ApartmentController;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\BaselineController;
use App\Controllers\BillingController;
use App\Controllers\BillingPeriodController;
use App\Controllers\BillNoticeController;
use App\Controllers\BuildingController;
use App\Controllers\ComparisonController;
use App\Controllers\ConsumptionTargetController;
use App\Controllers\CostAllocationController;
use App\Controllers\DashboardController;
use App\Controllers\EmailNotificationController;
use App\Controllers\ZaloNotificationController;
use App\Controllers\PeriodBillZaloController;
use App\Controllers\FixedFeeController;
use App\Controllers\ImportController;
use App\Controllers\MeterReadingController;
use App\Controllers\MeterController;
use App\Controllers\ParkingVehicleController;
use App\Controllers\RecommendationRuleController;
use App\Controllers\ReportController;
use App\Controllers\PeriodBillEmailController;
use App\Controllers\PeriodClosingController;
use App\Controllers\ReadingApprovalController;
use App\Controllers\ResidentController;
use App\Controllers\SystemParameterController;
use App\Controllers\TariffController;
use App\Controllers\PaymentCallbackController;
use App\Controllers\PaymentController;
use App\Controllers\TariffProposalController;
use App\Controllers\UserController;
use App\Controllers\ZoneController;

/** @var \App\Core\Router $router */
$router->get('/billing-adjustments', [\App\Controllers\BillingAdjustmentController::class, 'index'], ['auth','rbac:billing_adjustments.view']);
$router->get('/supplementary-debits/{id}', [\App\Controllers\BillingAdjustmentController::class, 'debit'], ['auth','rbac:billing_adjustments.view']);
$router->get('/charges/{id}/adjustments', [\App\Controllers\BillingAdjustmentController::class, 'charge'], ['auth','rbac:billing_adjustments.view']);
$router->post('/charges/{id}/adjustments', [\App\Controllers\BillingAdjustmentController::class, 'submit'], ['auth','rbac:billing_adjustments.submit','csrf']);
$router->get('/billing-adjustments/{id}', [\App\Controllers\BillingAdjustmentController::class, 'show'], ['auth','rbac:billing_adjustments.view']);
$router->post('/billing-adjustments/{id}/approve', [\App\Controllers\BillingAdjustmentController::class, 'approve'], ['auth','rbac:billing_adjustments.decide','csrf']);
$router->post('/billing-adjustments/{id}/reject', [\App\Controllers\BillingAdjustmentController::class, 'reject'], ['auth','rbac:billing_adjustments.decide','csrf']);
$router->post('/billing-adjustments/{id}/withdraw', [\App\Controllers\BillingAdjustmentController::class, 'withdraw'], ['auth','rbac:billing_adjustments.submit','csrf']);

// FN-01.01 / FN-01.02 - Đăng nhập / Đăng xuất
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login'], ['csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);
// Đổi ngôn ngữ giao diện vi / en (tuỳ chọn hiển thị trong session, như đăng xuất: không gắn quyền nghiệp vụ, không ghi DB)
$router->post('/locale', [LocaleController::class, 'switch'], ['auth', 'csrf']);

// FN-09.01 - Dashboard (shell). RBAC khai báo tường minh dù mọi role đều được
// phép, để chứng minh pipeline auth -> rbac hoạt động thật (không bỏ qua).
$router->get('/', [DashboardController::class, 'index'], ['auth', 'rbac:dashboard.view']);
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth', 'rbac:dashboard.view']);
// Hướng dẫn sử dụng theo vai trò (chỉ đọc, mọi vai trò đã đăng nhập)
$router->get('/help', [HelpController::class, 'index'], ['auth']);

// ============================================================================
// FN-02 Quản lý dữ liệu danh mục (STEP 6)
// - GET: auth + rbac:<module>.view|create|update
// - POST: auth + rbac + csrf (mọi thao tác ghi đều qua CSRF)
// - Route "/x/create" PHẢI khai báo trước "/x/{id}" (Router khớp route đầu tiên).
// ============================================================================

/**
 * Khai báo 7 route CRUD + deactivate chuẩn cho một module danh mục.
 * @param class-string $controller
 */
$masterDataRoutes = static function (\App\Core\Router $router, string $prefix, string $permission, string $controller): void {
    $router->get("/{$prefix}", [$controller, 'index'], ['auth', "rbac:{$permission}.view"]);
    $router->get("/{$prefix}/create", [$controller, 'create'], ['auth', "rbac:{$permission}.create"]);
    $router->post("/{$prefix}", [$controller, 'store'], ['auth', "rbac:{$permission}.create", 'csrf']);
    $router->get("/{$prefix}/{id}", [$controller, 'show'], ['auth', "rbac:{$permission}.view"]);
    $router->get("/{$prefix}/{id}/edit", [$controller, 'edit'], ['auth', "rbac:{$permission}.update"]);
    $router->post("/{$prefix}/{id}/update", [$controller, 'update'], ['auth', "rbac:{$permission}.update", 'csrf']);
    $router->post("/{$prefix}/{id}/deactivate", [$controller, 'deactivate'], ['auth', "rbac:{$permission}.deactivate", 'csrf']);
};

// FN-02.01 Tòa nhà
$masterDataRoutes($router, 'buildings', 'buildings', BuildingController::class);
// FN-02.02 Khu vực
$masterDataRoutes($router, 'zones', 'zones', ZoneController::class);
// FN-02.03 Căn hộ
$masterDataRoutes($router, 'apartments', 'apartments', ApartmentController::class);
// FN-02.05 Công tơ
$masterDataRoutes($router, 'meters', 'meters', MeterController::class);

// FN-02.04 Chủ hộ/khách thuê (danh sách theo căn hộ ở GET /apartments/{id})
$router->get('/apartments/{id}/residents/create', [ResidentController::class, 'create'], ['auth', 'rbac:residents.create']);
$router->post('/apartments/{id}/residents', [ResidentController::class, 'store'], ['auth', 'rbac:residents.create', 'csrf']);
$router->get('/residents/{id}/edit', [ResidentController::class, 'edit'], ['auth', 'rbac:residents.update']);
$router->post('/residents/{id}/update', [ResidentController::class, 'update'], ['auth', 'rbac:residents.update', 'csrf']);
$router->post('/residents/{id}/deactivate', [ResidentController::class, 'deactivate'], ['auth', 'rbac:residents.deactivate', 'csrf']);

// FN-02.06 Phương tiện gửi xe (xem ở GET /apartments/{id})
$router->get('/apartments/{id}/vehicles/edit', [ParkingVehicleController::class, 'edit'], ['auth', 'rbac:vehicles.update']);
$router->post('/apartments/{id}/vehicles', [ParkingVehicleController::class, 'update'], ['auth', 'rbac:vehicles.update', 'csrf']);

// ============================================================================
// FN-03 Biểu giá & phí (STEP 7). Không có route xoá: biểu giá/phí là phiên bản,
// lịch sử giữ nguyên (P1). "/x/create" khai báo trước "/x/{id}".
// ============================================================================

// FN-03.01 Biểu giá điện/nước
$router->get('/tariffs', [TariffController::class, 'index'], ['auth', 'rbac:tariffs.view']);
$router->get('/tariffs/create', [TariffController::class, 'create'], ['auth', 'rbac:tariffs.create']);
$router->post('/tariffs', [TariffController::class, 'store'], ['auth', 'rbac:tariffs.create', 'csrf']);
$router->get('/tariffs/{id}', [TariffController::class, 'show'], ['auth', 'rbac:tariffs.view']);
$router->get('/tariffs/{id}/edit', [TariffController::class, 'edit'], ['auth', 'rbac:tariffs.update']);
$router->post('/tariffs/{id}/update', [TariffController::class, 'update'], ['auth', 'rbac:tariffs.update', 'csrf']);

// FN-03.02 Phí cố định & phí gửi xe
$router->get('/fees', [FixedFeeController::class, 'index'], ['auth', 'rbac:fees.view']);
$router->get('/fees/create', [FixedFeeController::class, 'create'], ['auth', 'rbac:fees.create']);
$router->post('/fees', [FixedFeeController::class, 'store'], ['auth', 'rbac:fees.create', 'csrf']);
$router->get('/fees/{id}/edit', [FixedFeeController::class, 'edit'], ['auth', 'rbac:fees.update']);
$router->post('/fees/{id}/update', [FixedFeeController::class, 'update'], ['auth', 'rbac:fees.update', 'csrf']);

// FN-03.03 Đề xuất (Kế toán) / FN-03.04 Duyệt - từ chối (BQL)
$router->get('/tariff-proposals', [TariffProposalController::class, 'index'], ['auth', 'rbac:tariff_proposals.view']);
$router->get('/tariff-proposals/create', [TariffProposalController::class, 'create'], ['auth', 'rbac:tariff_proposals.create']);
$router->post('/tariff-proposals', [TariffProposalController::class, 'store'], ['auth', 'rbac:tariff_proposals.create', 'csrf']);
$router->get('/tariff-proposals/{id}', [TariffProposalController::class, 'show'], ['auth', 'rbac:tariff_proposals.view']);
$router->post('/tariff-proposals/{id}/approve', [TariffProposalController::class, 'approve'], ['auth', 'rbac:tariff_proposals.approve', 'csrf']);
$router->post('/tariff-proposals/{id}/reject', [TariffProposalController::class, 'reject'], ['auth', 'rbac:tariff_proposals.approve', 'csrf']);

// ============================================================================
// FN-04 Kỳ ghi chỉ số & chỉ số công tơ (STEP 8). Khoá/mở lại kỳ: xem FN-05 bên dưới.
// Không có "trả lại" chỉ số (BLOCKED B6) hay thay đồng hồ (BLOCKED B5).
// ============================================================================

// FN-04.01 Kỳ ghi chỉ số
$router->get('/periods', [BillingPeriodController::class, 'index'], ['auth', 'rbac:billing_periods.view']);
$router->get('/periods/create', [BillingPeriodController::class, 'create'], ['auth', 'rbac:billing_periods.create']);
$router->post('/periods', [BillingPeriodController::class, 'store'], ['auth', 'rbac:billing_periods.create', 'csrf']);
$router->get('/periods/{id}', [BillingPeriodController::class, 'show'], ['auth', 'rbac:billing_periods.view']);

// FN-04.02 Nhập tay
$router->get('/periods/{id}/readings/create', [MeterReadingController::class, 'create'], ['auth', 'rbac:meter_readings.create']);
$router->post('/periods/{id}/readings', [MeterReadingController::class, 'store'], ['auth', 'rbac:meter_readings.create', 'csrf']);

// FN-04.03 / 04.05 Import Excel + control total
$router->get('/periods/{id}/imports/create', [ImportController::class, 'create'], ['auth', 'rbac:meter_readings.import']);
$router->get('/periods/{id}/imports/template', [ImportController::class, 'template'], ['auth', 'rbac:meter_readings.import']);
$router->post('/periods/{id}/imports', [ImportController::class, 'store'], ['auth', 'rbac:meter_readings.import', 'csrf']);
$router->get('/imports/{id}', [ImportController::class, 'show'], ['auth', 'rbac:meter_readings.view']);
$router->post('/imports/{id}/confirm', [ImportController::class, 'confirm'], ['auth', 'rbac:meter_readings.import', 'csrf']);
$router->post('/imports/{id}/cancel', [ImportController::class, 'cancel'], ['auth', 'rbac:meter_readings.import', 'csrf']);

// FN-04.08 Phê duyệt (từng bản / theo lô)
$router->get('/periods/{id}/approvals', [ReadingApprovalController::class, 'index'], ['auth', 'rbac:meter_readings.approve']);
$router->post('/periods/{id}/approvals', [ReadingApprovalController::class, 'store'], ['auth', 'rbac:meter_readings.approve', 'csrf']);

// FN-04.09 Tra cứu + FN-04.07 Sửa trước khoá kỳ
$router->get('/readings', [MeterReadingController::class, 'index'], ['auth', 'rbac:meter_readings.view']);
$router->get('/readings/{id}', [MeterReadingController::class, 'show'], ['auth', 'rbac:meter_readings.view']);
$router->get('/readings/{id}/edit', [MeterReadingController::class, 'edit'], ['auth', 'rbac:meter_readings.update']);
$router->post('/readings/{id}/update', [MeterReadingController::class, 'update'], ['auth', 'rbac:meter_readings.update', 'csrf']);

// ============================================================================
// FN-05 Tính tiêu thụ, chi phí & chốt kỳ (STEP 9). Không có route phát hành phiếu (FN-09.02),
// phí mở lại dịch vụ (BLOCKED J-09) hay phiếu thay thế (BLOCKED J-19/B12).
// ============================================================================

// FN-05.02 - 05.04 Tính phí (theo lệnh Kế toán - J-14) + danh sách/chi tiết
$router->get('/periods/{id}/billing', [BillingController::class, 'index'], ['auth', 'rbac:billing.view']);
$router->post('/periods/{id}/billing/calculate', [BillingController::class, 'calculate'], ['auth', 'rbac:billing.calculate', 'csrf']);
$router->get('/charges/{id}', [BillingController::class, 'show'], ['auth', 'rbac:billing.view']);
$router->post('/charges/{id}/old-debt', [BillingController::class, 'oldDebt'], ['auth', 'rbac:billing.update_debt', 'csrf']);

// FN-05.06 Kế toán kiểm tra & xác nhận / trả lại; BQL duyệt (tuỳ chọn J-18)
$router->get('/periods/{id}/billing/review', [BillingController::class, 'review'], ['auth', 'rbac:billing.confirm']);
$router->post('/periods/{id}/billing/confirm', [BillingController::class, 'confirm'], ['auth', 'rbac:billing.confirm', 'csrf']);
$router->post('/charges/{id}/return', [BillingController::class, 'returnCharge'], ['auth', 'rbac:billing.confirm', 'csrf']);
$router->get('/periods/{id}/billing/approvals', [BillingController::class, 'approvals'], ['auth', 'rbac:billing.approve']);
$router->post('/periods/{id}/billing/approve', [BillingController::class, 'approve'], ['auth', 'rbac:billing.approve', 'csrf']);

// FN-05.05 Tổng hợp chi phí theo căn / khu / tòa
$router->get('/periods/{id}/cost-allocation', [CostAllocationController::class, 'index'], ['auth', 'rbac:cost_allocation.view']);

// FN-05.07 Khoá kỳ / FN-05.08 Mở lại kỳ
$router->post('/periods/{id}/lock', [PeriodClosingController::class, 'lock'], ['auth', 'rbac:billing_periods.lock', 'csrf']);
$router->post('/periods/{id}/reopen', [PeriodClosingController::class, 'reopen'], ['auth', 'rbac:billing_periods.reopen', 'csrf']);
// FN-10.03 kết quả gửi email phiếu thông báo phí theo kỳ (tự gửi sau khoá kỳ)
$router->get('/periods/{id}/bill-emails', [PeriodBillEmailController::class, 'index'], ['auth', 'rbac:email_notifications.view']);
$router->post('/periods/{id}/bill-emails/send', [PeriodBillEmailController::class, 'send'], ['auth', 'rbac:email_notifications.send', 'csrf']);
$router->post('/periods/{id}/bill-emails/retry-failed', [PeriodBillEmailController::class, 'retryFailed'], ['auth', 'rbac:email_notifications.send', 'csrf']);
// Gửi phiếu phí qua Zalo (ZNS) theo kỳ - cùng quyền với gửi email
$router->post('/periods/{id}/bill-zalo/send', [PeriodBillZaloController::class, 'send'], ['auth', 'rbac:zalo_notifications.send', 'csrf']);
$router->post('/periods/{id}/bill-zalo/retry-failed', [PeriodBillZaloController::class, 'retryFailed'], ['auth', 'rbac:zalo_notifications.send', 'csrf']);

// ============================================================================
// FN-06 Baseline, so sánh & mục tiêu (STEP 10). Không có route cảnh báo / ngưỡng (FN-07).
// ============================================================================

// FN-06.01 Baseline (BQL) - POST /baselines và /baselines/{id}/update nhận intent=preview (tính thử) | save
$router->get('/baselines', [BaselineController::class, 'index'], ['auth', 'rbac:baselines.view']);
$router->get('/baselines/create', [BaselineController::class, 'create'], ['auth', 'rbac:baselines.manage']);
$router->post('/baselines', [BaselineController::class, 'store'], ['auth', 'rbac:baselines.manage', 'csrf']);
$router->get('/baselines/{id}', [BaselineController::class, 'show'], ['auth', 'rbac:baselines.view']);
$router->get('/baselines/{id}/edit', [BaselineController::class, 'edit'], ['auth', 'rbac:baselines.manage']);
$router->post('/baselines/{id}/update', [BaselineController::class, 'update'], ['auth', 'rbac:baselines.manage', 'csrf']);
$router->post('/baselines/{id}/deactivate', [BaselineController::class, 'deactivate'], ['auth', 'rbac:baselines.manage', 'csrf']);

// FN-06.02 So sánh
$router->get('/comparisons', [ComparisonController::class, 'index'], ['auth', 'rbac:comparisons.view']);

// FN-06.03 Mục tiêu / FN-06.04 Theo dõi mức đạt
$router->get('/targets', [ConsumptionTargetController::class, 'index'], ['auth', 'rbac:targets.view']);
$router->get('/targets/tracking', [ConsumptionTargetController::class, 'tracking'], ['auth', 'rbac:targets.view']);
$router->get('/targets/create', [ConsumptionTargetController::class, 'create'], ['auth', 'rbac:targets.manage']);
$router->post('/targets', [ConsumptionTargetController::class, 'store'], ['auth', 'rbac:targets.manage', 'csrf']);
$router->get('/targets/{id}/edit', [ConsumptionTargetController::class, 'edit'], ['auth', 'rbac:targets.manage']);
$router->post('/targets/{id}/update', [ConsumptionTargetController::class, 'update'], ['auth', 'rbac:targets.manage', 'csrf']);

// ============================================================================
// FN-07 Cảnh báo & xử lý bất thường (STEP 11). Không có khuyến nghị (FN-08), lịch/ảnh/hạn kiểm tra (FN-10.01).
// ============================================================================

// FN-07.01 Quy tắc ngưỡng
$router->get('/alert-rules', [AlertRuleController::class, 'index'], ['auth', 'rbac:alert_rules.view']);
$router->get('/alert-rules/create', [AlertRuleController::class, 'create'], ['auth', 'rbac:alert_rules.manage']);
$router->post('/alert-rules', [AlertRuleController::class, 'store'], ['auth', 'rbac:alert_rules.manage', 'csrf']);
$router->get('/alert-rules/{id}', [AlertRuleController::class, 'show'], ['auth', 'rbac:alert_rules.view']); // ?period_id= chạy thử
$router->get('/alert-rules/{id}/edit', [AlertRuleController::class, 'edit'], ['auth', 'rbac:alert_rules.manage']);
$router->post('/alert-rules/{id}/update', [AlertRuleController::class, 'update'], ['auth', 'rbac:alert_rules.manage', 'csrf']);
$router->post('/alert-rules/{id}/status', [AlertRuleController::class, 'status'], ['auth', 'rbac:alert_rules.manage', 'csrf']);

// FN-07.02 - 07.10 Cảnh báo
$router->get('/alerts', [AlertController::class, 'index'], ['auth', 'rbac:alerts.view']);
$router->post('/alerts/evaluate', [AlertController::class, 'evaluate'], ['auth', 'rbac:alerts.evaluate', 'csrf']);
$router->get('/alerts/create', [AlertController::class, 'create'], ['auth', 'rbac:alerts.create_manual']);
$router->post('/alerts', [AlertController::class, 'store'], ['auth', 'rbac:alerts.create_manual', 'csrf']);
$router->get('/alerts/{id}', [AlertController::class, 'show'], ['auth', 'rbac:alerts.view']);
$router->post('/alerts/{id}/review', [AlertController::class, 'review'], ['auth', 'rbac:alerts.review', 'csrf']);
$router->post('/alerts/{id}/priority', [AlertController::class, 'priority'], ['auth', 'rbac:alerts.review', 'csrf']);
$router->post('/alerts/{id}/request-verification', [AlertController::class, 'requestVerification'], ['auth', 'rbac:alerts.request_verification', 'csrf']);
$router->post('/alerts/{id}/verification-result', [AlertController::class, 'verificationResult'], ['auth', 'rbac:alerts.update_verification', 'csrf']);
$router->post('/alerts/{id}/confirm', [AlertController::class, 'confirm'], ['auth', 'rbac:alerts.confirm', 'csrf']);
$router->post('/alerts/{id}/close', [AlertController::class, 'close'], ['auth', 'rbac:alerts.close', 'csrf']);
$router->post('/alerts/{id}/reopen', [AlertController::class, 'reopen'], ['auth', 'rbac:alerts.close', 'csrf']);
// [BA Proposal J-16] căn khai báo "không phát sinh" có CL > 0
$router->post('/alerts/{id}/j16/contact', [AlertController::class, 'j16Contact'], ['auth', 'rbac:alerts.contact_resident', 'csrf']);
$router->post('/alerts/{id}/j16/email', [AlertController::class, 'j16Email'], ['auth', 'rbac:alerts.contact_resident', 'csrf']);
$router->post('/alerts/{id}/j16/conclude', [AlertController::class, 'j16Conclude'], ['auth', 'rbac:alerts.conclude', 'csrf']);
$router->post('/alerts/{id}/j16/apartment-status', [AlertController::class, 'j16ApartmentStatus'], ['auth', 'rbac:alerts.conclude', 'csrf']);
$router->post('/alerts/{id}/j16/adjust-reading', [AlertController::class, 'j16AdjustReading'], ['auth', 'rbac:alerts.conclude', 'csrf']);

// ============================================================================
// FN-08 Khuyến nghị theo quy tắc (STEP 12). Không AI; không tự thực hiện thao tác nghiệp vụ.
// ============================================================================
$router->get('/recommendation-rules', [RecommendationRuleController::class, 'index'], ['auth', 'rbac:recommendation_rules.view']);
$router->get('/recommendation-rules/create', [RecommendationRuleController::class, 'create'], ['auth', 'rbac:recommendation_rules.manage']);
$router->post('/recommendation-rules', [RecommendationRuleController::class, 'store'], ['auth', 'rbac:recommendation_rules.manage', 'csrf']);
$router->get('/recommendation-rules/{id}', [RecommendationRuleController::class, 'show'], ['auth', 'rbac:recommendation_rules.view']);
$router->get('/recommendation-rules/{id}/edit', [RecommendationRuleController::class, 'edit'], ['auth', 'rbac:recommendation_rules.manage']);
$router->post('/recommendation-rules/{id}/update', [RecommendationRuleController::class, 'update'], ['auth', 'rbac:recommendation_rules.manage', 'csrf']);
$router->post('/recommendation-rules/{id}/status', [RecommendationRuleController::class, 'status'], ['auth', 'rbac:recommendation_rules.manage', 'csrf']);
$router->post('/alerts/{id}/recommendations', [AlertController::class, 'generateRecommendations'], ['auth', 'rbac:recommendations.generate', 'csrf']);

// ============================================================================
// FN-09 Dashboard & báo cáo (STEP 13). CHỈ ĐỌC. Dashboard: GET /dashboard (DashboardController, ở đầu file).
// ============================================================================
$router->get('/charges/{id}/notice', [BillNoticeController::class, 'show'], ['auth', 'rbac:bills.view']);
$router->get('/periods/{id}/notices', [BillNoticeController::class, 'period'], ['auth', 'rbac:bills.view']);
$router->get('/reports/utility', [ReportController::class, 'utility'], ['auth', 'rbac:reports.view']);
$router->get('/reports/utility/export', [ReportController::class, 'export'], ['auth', 'rbac:reports.export']);

// ============================================================================
// STEP 15 - FN-01.03 Quản lý tài khoản, FN-01.06 Tra cứu audit, FN-01.07 Tham số hệ thống
// ============================================================================
$router->get('/users', [UserController::class, 'index'], ['auth', 'rbac:users.view']);
$router->get('/users/create', [UserController::class, 'create'], ['auth', 'rbac:users.manage']);
$router->post('/users', [UserController::class, 'store'], ['auth', 'rbac:users.manage', 'csrf']);
$router->get('/users/{id}', [UserController::class, 'show'], ['auth', 'rbac:users.view']);
$router->get('/users/{id}/edit', [UserController::class, 'edit'], ['auth', 'rbac:users.manage']);
$router->post('/users/{id}/update', [UserController::class, 'update'], ['auth', 'rbac:users.manage', 'csrf']);
$router->post('/users/{id}/status', [UserController::class, 'status'], ['auth', 'rbac:users.change_status', 'csrf']);
$router->get('/users/{id}/password', [UserController::class, 'passwordForm'], ['auth', 'rbac:users.reset_password']);
$router->post('/users/{id}/password', [UserController::class, 'password'], ['auth', 'rbac:users.reset_password', 'csrf']);
$router->get('/audit-logs', [AuditLogController::class, 'index'], ['auth', 'rbac:audit_logs.view']);
$router->get('/audit-logs/{id}', [AuditLogController::class, 'show'], ['auth', 'rbac:audit_logs.view']);
$router->get('/system-parameters', [SystemParameterController::class, 'index'], ['auth', 'rbac:system_parameters.view']);
$router->get('/system-parameters/{id}/edit', [SystemParameterController::class, 'edit'], ['auth', 'rbac:system_parameters.manage']);
$router->post('/system-parameters/{id}/update', [SystemParameterController::class, 'update'], ['auth', 'rbac:system_parameters.manage', 'csrf']);

// ============================================================================
// STEP 25 - FN-10.03 (Secondary) gửi thông báo phí qua email + hộp thư đi
// ============================================================================
$router->post('/charges/{id}/email', [EmailNotificationController::class, 'send'], ['auth', 'rbac:email_notifications.send', 'csrf']);
// Payment module: dashboard + đối soát (trạng thái thanh toán tính từ payment_transactions; không ghi apartment_charges)
$router->get('/payments', [PaymentController::class, 'index'], ['auth', 'rbac:payments.view']);
$router->get('/payments/review', [PaymentController::class, 'review'], ['auth', 'rbac:payments.view']);
$router->post('/payments/{id}/match', [PaymentController::class, 'match'], ['auth', 'rbac:payments.review', 'csrf']);
$router->post('/payments/{id}/ignore', [PaymentController::class, 'ignore'], ['auth', 'rbac:payments.review', 'csrf']);
// Mock Bank: ứng dụng giả lập ĐỘC LẬP (tools/mock-bank/, chạy riêng - không thuộc Energy DSS); chỉ gọi callback bên dưới.
// Webhook ngân hàng giả lập: máy gọi máy - KHÔNG phiên / CSRF; bảo vệ bằng HMAC-SHA256 + tắt mặc định (404) + giới hạn kích thước
$router->post('/payments/callback', [PaymentCallbackController::class, 'handle'], []);

$router->get('/email-notifications', [EmailNotificationController::class, 'index'], ['auth', 'rbac:email_notifications.view']);
$router->get('/email-notifications/{id}', [EmailNotificationController::class, 'show'], ['auth', 'rbac:email_notifications.view']);
$router->post('/email-notifications/{id}/retry', [EmailNotificationController::class, 'retry'], ['auth', 'rbac:email_notifications.send', 'csrf']);

// Gửi phiếu phí qua Zalo (ZNS) + Hộp thư Zalo
$router->post('/charges/{id}/zalo', [ZaloNotificationController::class, 'send'], ['auth', 'rbac:zalo_notifications.send', 'csrf']);
$router->get('/zalo-notifications', [ZaloNotificationController::class, 'index'], ['auth', 'rbac:zalo_notifications.view']);
$router->get('/zalo-notifications/{id}', [ZaloNotificationController::class, 'show'], ['auth', 'rbac:zalo_notifications.view']);
$router->post('/zalo-notifications/{id}/retry', [ZaloNotificationController::class, 'retry'], ['auth', 'rbac:zalo_notifications.send', 'csrf']);
