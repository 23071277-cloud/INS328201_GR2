<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\BillNoticeService;

/**
 * FN-09.02 Phiếu thông báo kiêm phiếu thu (Kế toán; BQL xem). Trang in HTML + CSS print -> "In / Lưu PDF" của trình duyệt
 * (không thư viện, không dịch vụ ngoài). CHỈ ĐỌC: không đổi trạng thái bản tính.
 */
class BillNoticeController extends BaseController
{
    public function show(string $id): void
    {
        $chargeId = $this->routeId($id);
        try {
            $notice = (new BillNoticeService())->forCharge($chargeId);
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
            $this->redirect('/charges/' . $chargeId);
        }

        // STEP 25 (FN-10.03): người nhận + lịch sử gửi email của phiếu này - chỉ khi có quyền
        $email = null;
        if (can('email_notifications.send') || can('email_notifications.view')) {
            $emailService = new \App\Services\EmailNotificationService();
            $email = ['recipients' => can('email_notifications.send') ? $emailService->recipientsFor($notice) : [],
                'history' => can('email_notifications.view') ? $emailService->historyForCharge($chargeId) : []];
        }

        // Gửi Zalo (ZNS): người nhận có SĐT + lịch sử - chỉ khi có quyền và bảng Zalo đã migrate (null = ẩn khối Zalo)
        $zalo = null;
        $zaloService = new \App\Services\ZaloNotificationService();
        if ((can('zalo_notifications.send') || can('zalo_notifications.view')) && $zaloService->available()) {
            $zalo = ['recipients' => can('zalo_notifications.send') ? $zaloService->recipientsFor($notice) : [],
                'history' => can('zalo_notifications.view') ? $zaloService->historyForCharge($chargeId) : [], 'driver' => $zaloService->driver()];
        }

        $this->view('bills.notice', ['title' => 'Phiếu thông báo phí — ' . $notice['charge']['apartment_code'], 'notices' => [$notice], 'skipped' => [], 'period' => $notice['period'], 'email' => $email, 'zalo' => $zalo]);
    }

    public function period(string $id): void
    {
        $periodId = $this->routeId($id);
        try {
            $batch = (new BillNoticeService())->forPeriod($periodId);
        } catch (ValidationException $e) {
            $this->notFound();
        }

        $this->view('bills.notice', ['title' => 'Phiếu thông báo phí — kỳ ' . $batch['period']['period_code'], 'notices' => $batch['notices'], 'skipped' => $batch['skipped'], 'period' => $batch['period']]);
    }
}
