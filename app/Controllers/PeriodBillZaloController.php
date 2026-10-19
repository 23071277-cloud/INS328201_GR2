<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\PeriodBillZaloService;
use App\Services\ZaloNotificationService;

/** Gửi phiếu phí qua Zalo THEO KỲ (kỳ đã khoá, bấm tay) + gửi lại tin lỗi. back=period -> quay về trang chi tiết kỳ. */
class PeriodBillZaloController extends BaseController
{
    public function send(string $id): void
    {
        $periodId = $this->routeId($id);
        (new \App\Repositories\BillingPeriodRepository())->find($periodId) ?? $this->notFound();
        if (!(new ZaloNotificationService())->available()) {
            Flash::error('Chưa cài đặt chức năng Zalo trên cơ sở dữ liệu này (cần chạy migration 20261018_zalo_notifications.sql).');
            $this->back($periodId);
        }
        try {
            $s = (new PeriodBillZaloService())->sendForPeriod($periodId, 'manual');
            Flash::success('Gửi Zalo cho các hộ: ' . $s['sent'] . ' thành công, ' . count($s['missing']) . ' thiếu số điện thoại, ' . $s['failed'] . ' lỗi'
                . ($s['already'] > 0 ? ', ' . $s['already'] . ' đã gửi trước đó (không gửi trùng)' : '') . '.');
            if ($s['failed'] > 0) {
                Flash::info('Có ' . $s['failed'] . ' tin lỗi — bấm "Gửi lại tin Zalo lỗi" để gửi lại.');
            }
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->back($periodId);
    }

    public function retryFailed(string $id): void
    {
        $periodId = $this->routeId($id);
        (new \App\Repositories\BillingPeriodRepository())->find($periodId) ?? $this->notFound();
        (new ZaloNotificationService())->available() || $this->notFound();
        $r = (new PeriodBillZaloService())->retryFailed($periodId);
        $r['retried'] === 0 ? Flash::info('Không có tin Zalo lỗi cần gửi lại.')
            : Flash::success("Gửi lại {$r['retried']} tin Zalo lỗi: {$r['sent']} thành công, {$r['failed']} vẫn lỗi.");
        $this->back($periodId);
    }

    private function back(int $periodId): void
    {
        $this->redirect(Request::postString('back') === 'period' ? "/periods/{$periodId}" : "/periods/{$periodId}/bill-emails");
    }
}
