<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\DashboardRepository;

/**
 * FN-09.02 Phiếu thông báo kiêm phiếu thu - FR-12, UC-03 b4–5, BR-38, BR-24, BR-36. CHỈ ĐỌC:
 * số liệu = bản tính FN-05 đã lưu (BillingCalculationService::detail - không tính lại, không dùng biểu giá hiện tại).
 * Chỉ bản tính Đã xác nhận trở lên và còn hiện hành (kỳ đã khoá: dữ liệu lịch sử). Không ghi nhận "Đã phát hành"
 * (NEED BA - J-19); phiếu thay thế BLOCKED J-19/B12. Không hiện số điện thoại (BR-36).
 */
class BillNoticeService
{
    /** @return array notice (dữ liệu in) */
    public function forCharge(int $chargeId): array
    {
        $detail = (new BillingCalculationService())->detail($chargeId);
        if ($detail === null) {
            throw ValidationException::withMessage('Bản tính không tồn tại.');
        }
        $c = $detail['charge'];
        if (!in_array($c['status'], config('billing.lockable_statuses'), true)) {
            throw ValidationException::withMessage("Dữ liệu căn hộ {$c['apartment_code']} chưa được xác nhận."); // [ĐX] FN-09.02 VAL
        }
        if (!in_array($detail['consistency']['state'], ['current', 'locked'], true)) {
            throw ValidationException::withMessage("Bản tính căn hộ {$c['apartment_code']} CẦN TÍNH LẠI — không xuất phiếu.");
        }
        $owners = (new DashboardRepository())->owners([(int) $c['apartment_id']]);

        return $detail + [
            'owner' => $owners[(int) $c['apartment_id']] ?? null,
            'bank_account' => SystemParameterService::get('bank_account_info'), // FN-01.07
        ];
    }

    /** Hàng loạt theo kỳ: phiếu xuất được + danh sách căn bị loại kèm lý do. */
    public function forPeriod(int $periodId): array
    {
        $period = (new BillingPeriodRepository())->find($periodId);
        if ($period === null) {
            throw ValidationException::withMessage('Kỳ không tồn tại.');
        }
        $notices = [];
        $skipped = [];
        foreach ((new ApartmentChargeRepository())->listByPeriod($periodId) as $c) {
            try {
                $notices[] = $this->forCharge((int) $c['id']);
            } catch (ValidationException $e) {
                $skipped[] = ['apartment_code' => $c['building_code'] . ' / ' . $c['apartment_code'], 'reason' => $e->getMessage()];
            }
        }

        return ['period' => $period, 'notices' => $notices, 'skipped' => $skipped];
    }
}
