<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\BillingPeriodService;
use App\Services\PeriodClosingService;

/** FN-04.01 Tạo kỳ ghi chỉ số + danh sách/chi tiết kỳ (khoá/mở lại kỳ thuộc FN-05). */
class BillingPeriodController extends BaseController
{
    private BillingPeriodService $service;

    public function __construct()
    {
        $this->service = new BillingPeriodService();
    }

    public function index(): void
    {
        $filters = $this->filters(['status', 'year']);

        $this->view('periods.index', [
            'title' => t('page.periods'),
            'filters' => $filters,
            'pager' => $this->service->list($filters, $this->currentPage()),
            'years' => $this->service->years(),
        ]);
    }

    public function create(): void
    {
        $this->view('periods.create', [
            'title' => 'Tạo kỳ ghi chỉ số',
            'suggestion' => $this->service->suggestion(),
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(): void
    {
        $input = $this->only(['month', 'year', 'start_date', 'end_date', 'reading_due_date']);

        try {
            $result = $this->service->create($input);
        } catch (ValidationException $e) {
            $this->backWithErrors('/periods/create', $e, $input);
        }

        Flash::success('Đã tạo kỳ ghi chỉ số. Danh sách công tơ cần ghi số được lập theo công tơ đang hoạt động.');
        if ($result['warning'] !== null) {
            Flash::info($result['warning']);
        }
        $this->redirect('/periods/' . $result['id']);
    }

    public function show(string $id): void
    {
        $period = $this->service->detail($this->routeId($id)) ?? $this->notFound();

        $closing = new PeriodClosingService();
        $periodId = (int) $period['id'];
        $billing = $closing->billingSummary($periodId);

        $this->view('periods.show', [
            'title' => 'Kỳ ' . $period['period_code'],
            'period' => $period,
            // FN-05: tóm tắt tính phí (số tiền chỉ ở màn hình billing - quyền billing.view)
            'chargeCounts' => $billing['counts'],
            'returned' => $billing['returned'], // Q17: KT/BQL nhận thông tin trả lại
            // FN-10.03: tình trạng Gmail phiếu thông báo phí (chỉ kỳ đã khoá, quyền xem email)
            'billEmail' => $period['status'] === 'da_khoa' && can('email_notifications.view') ? (new \App\Services\PeriodBillEmailService())->report($periodId)['count'] : null,
            // Gửi Zalo (ZNS): tình trạng theo kỳ - chỉ khi kỳ đã khoá, có quyền xem và bảng Zalo đã migrate
            'billZalo' => $period['status'] === 'da_khoa' && can('zalo_notifications.view') && (new \App\Services\ZaloNotificationService())->available()
                ? (new \App\Services\PeriodBillZaloService())->counts($periodId) : null,
            'blockers' => can('billing_periods.lock') && $period['status'] !== 'da_khoa' ? $closing->lockBlockers($periodId) : null,
            'form' => Flash::pullFormState(),
            // Payment module: cảnh báo mạnh trong hộp thoại Mở lại kỳ khi kỳ đã có thanh toán
            'paymentsRecorded' => $period['status'] === 'da_khoa' && can('billing_periods.reopen') ? (new \App\Services\PaymentStatusService())->recordedForPeriod($periodId) : null,
        ]);
    }
}
