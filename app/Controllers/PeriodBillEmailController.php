<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\PeriodBillEmailService;

/**
 * FN-10.03 - gửi Gmail phiếu thông báo phí THEO KỲ (thủ công, kỳ đã khoá) + Hộp thư theo kỳ.
 * BQL / Kế toán xem (email_notifications.view); gửi / gửi lại theo quyền email_notifications.send. back=period -> quay về trang chi tiết kỳ.
 */
class PeriodBillEmailController extends BaseController
{
    public function index(string $id): void
    {
        $periodId = $this->routeId($id);
        try {
            $report = (new PeriodBillEmailService())->report($periodId);
        } catch (ValidationException $e) {
            $this->notFound();
        }
        $zalo = null;
        if (can('zalo_notifications.view') && (new \App\Services\ZaloNotificationService())->available()) {
            $zalo = (new \App\Services\PeriodBillZaloService())->counts($periodId);
        }
        $this->view('bill_emails.index', ['title' => 'Hộp thư theo kỳ — ' . $report['period']['period_code'], 'r' => $report, 'zalo' => $zalo]);
    }

    /** "Gửi Gmail cho các hộ": gửi cho mọi căn / người nhận chưa có thư (không gửi trùng; bấm lại sau khi bổ sung email cư dân). */
    public function send(string $id): void
    {
        $periodId = $this->routeId($id);
        (new \App\Repositories\BillingPeriodRepository())->find($periodId) ?? $this->notFound();
        try {
            $s = (new PeriodBillEmailService())->sendForPeriod($periodId, 'manual');
            Flash::success('Gửi Gmail cho các hộ: ' . $s['sent'] . ' gửi thành công, ' . count($s['missing']) . ' thiếu email, ' . $s['failed'] . ' lỗi'
                . ($s['already'] > 0 ? ', ' . $s['already'] . ' đã gửi trước đó (không gửi trùng)' : '') . '.');
            if ($s['failed'] > 0) {
                Flash::info('Có ' . $s['failed'] . ' thư lỗi — bấm "Gửi lại thư lỗi" để gửi lại.');
            }
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->back($periodId);
    }

    public function retryFailed(string $id): void
    {
        $periodId = $this->routeId($id);
        (new \App\Repositories\BillingPeriodRepository())->find($periodId) ?? $this->notFound(); // kỳ không tồn tại -> 404 (không 500)
        $r = (new PeriodBillEmailService())->retryFailed($periodId);
        $r['retried'] === 0 ? Flash::info('Không có email lỗi cần gửi lại.')
            : Flash::success("Gửi lại {$r['retried']} email lỗi: {$r['sent']} thành công, {$r['failed']} vẫn lỗi.");
        $this->back($periodId);
    }

    private function back(int $periodId): void
    {
        $this->redirect(Request::postString('back') === 'period' ? "/periods/{$periodId}" : "/periods/{$periodId}/bill-emails");
    }
}
