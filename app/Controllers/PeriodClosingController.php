<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\PeriodClosingService;

/** FN-05.07 Khoá kỳ / FN-05.08 Mở lại kỳ (BQL, lý do bắt buộc). */
class PeriodClosingController extends BaseController
{
    private PeriodClosingService $service;

    public function __construct()
    {
        $this->service = new PeriodClosingService();
    }

    public function lock(string $id): void
    {
        $periodId = $this->routeId($id);
        try {
            $this->service->lock($periodId);
            // FN-10.03 [ASSUMPTION A-EMAIL-08]: khoá kỳ KHÔNG tự gửi email - Kế toán / BQL bấm "Gửi Gmail cho các hộ" trên kỳ đã khoá
            Flash::success('Khóa kỳ thành công.');
            if (can('email_notifications.send')) {
                Flash::info('Có thể bấm "Gửi Gmail cho các hộ" để gửi phiếu thông báo phí của kỳ.');
            }
        } catch (ValidationException $e) {
            Flash::error('Chưa khóa được kỳ:');
            foreach (array_merge(...array_values($e->errors())) as $reason) {
                Flash::error($reason);
            }
        }
        $this->redirect(Request::postString('back') === 'billing' ? "/periods/{$periodId}/billing" : "/periods/{$periodId}");
    }

    public function reopen(string $id): void
    {
        $periodId = $this->routeId($id);
        $reason = Request::postString('reopen_reason');
        try {
            $warnings = $this->service->reopen($periodId, $reason, Request::postString('confirm_payments') === '1');
            Flash::success('Đã mở lại kỳ (Mở lại). Bản tính cũ được giữ nguyên; dữ liệu sửa sau đó phải tính lại và xác nhận lại trước khi khóa.');
            foreach ($warnings as $warning) {
                Flash::info($warning);
            }
        } catch (ValidationException $e) {
            $this->backWithErrors("/periods/{$periodId}", $e, ['reopen_reason' => $reason]);
        }
        $this->redirect("/periods/{$periodId}");
    }
}
