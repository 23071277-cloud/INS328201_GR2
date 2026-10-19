<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ApartmentChargeRepository;

/**
 * FN-05.05 Phân bổ & tổng hợp chi phí theo căn hộ / khu vực / tòa - BR-35, BR-11.
 * Tổng hợp từ apartment_charges bằng SQL GROUP BY (mỗi bản tính đếm 1 lần; không join bảng bậc nên
 * không nhân đôi). Chi phí = tổng phát sinh kỳ (không gồm nợ cũ).
 *   TB/căn = tổng chi phí ÷ số căn được tính phí (BR-35); [ĐX] chi phí/m² = tổng chi phí ÷ tổng DT.
 * Phân bổ hoá đơn tổng / khu vực chung: NEED BA CONFIRMATION J-17 (không có dữ liệu).
 */
class CostAllocationService
{
    private ApartmentChargeRepository $charges;

    public function __construct()
    {
        $this->charges = new ApartmentChargeRepository();
    }

    /**
     * @param bool $confirmedOnly true = chỉ bản tính đã xác nhận trở lên (BR-11); false = mọi bản tính
     * @return array{building:array, zone:array, apartment:array, statuses:string[]}
     */
    public function summary(int $periodId, bool $confirmedOnly): array
    {
        $statuses = $confirmedOnly ? config('billing.lockable_statuses') : array_keys(config('billing.charge_status'));
        $out = ['statuses' => $statuses];
        foreach (['building', 'zone', 'apartment'] as $level) {
            $out[$level] = array_map([$this, 'withAverages'], $this->charges->aggregate($periodId, $level, $statuses));
        }

        return $out;
    }

    private function withAverages(array $row): array
    {
        $count = (int) $row['charged_apartments'];
        $total = MoneyCalculator::fromDb($row['total_cost'] ?? '0');
        $area = MoneyCalculator::fromDb($row['total_area'] ?? '0');
        $row['average_per_apartment'] = MoneyCalculator::divide($total, $count);
        // tổng chi phí (xu) ÷ DT (m², xu) -> đ/m² (xu): nhân 100 trước khi chia
        $row['cost_per_m2'] = $area > 0 ? MoneyCalculator::divide($total * 100, $area) : null;

        return $row;
    }
}
