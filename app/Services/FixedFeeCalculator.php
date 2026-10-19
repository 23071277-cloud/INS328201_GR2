<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Decimal;

/**
 * FN-05.03 - phí cố định theo diện tích & phí gửi xe của MỘT căn (hàm thuần, không đọc DB).
 *   Phí DV  = DT × đơn giá (dich_vu_co_o / dich_vu_khong_o theo config billing.service_fee) [ASSUMPTION J-16 / A-12]
 *   BHCN    = DT × đơn giá (nếu config billing.fire_insurance.enabled)                   [ASSUMPTION J-08]
 *   Gửi xe  = Σ(số lượng × đơn giá loại xe)                                              BR-17
 *   Mở lại dịch vụ: KHÔNG tính (BLOCKED J-09/B11).
 * Lỗi dữ liệu (DT ≤ 0 - VAL-05.02, thiếu đơn giá) -> trả trong 'errors', căn không được tính.
 */
final class FixedFeeCalculator
{
    /**
     * @param array $apartment dòng apartments (area, occupancy_status)
     * @param array<string,int> $vehicles vehicle_type => quantity
     * @param array<string,?array> $rates fee_type => dòng fixed_fee_rates hiệu lực (null = không có)
     * @return array{lines:array<int,array>, errors:string[], not_calculated:array<int,array>}
     */
    public static function calculate(array $apartment, array $vehicles, array $rates): array
    {
        $lines = [];
        $errors = [];
        $notCalculated = [];

        $area = Decimal::toCents((string) ($apartment['area'] ?? ''));
        if ($area === null || $area <= 0) {
            $errors[] = 'Diện tích căn hộ thiếu hoặc ≤ 0 — không tính phí theo m².';
        }

        // Phí dịch vụ - BR-15
        $occupancy = (string) ($apartment['occupancy_status'] ?? '');
        $serviceType = config('billing.service_fee')[$occupancy] ?? null;
        if ($serviceType === null) {
            $errors[] = "Không xác định được mức phí dịch vụ cho trạng thái căn '{$occupancy}'.";
        } elseif ($area !== null && $area > 0) {
            self::areaLine($lines, $errors, 'service', $serviceType, $area, $rates[$serviceType] ?? null);
        }

        // BHCN - BR-16, chu kỳ chưa chốt J-08
        $fire = config('billing.fire_insurance');
        if (!empty($fire['enabled'])) {
            if ($area !== null && $area > 0) {
                self::areaLine($lines, $errors, 'fire_insurance', $fire['fee_type'], $area, $rates[$fire['fee_type']] ?? null);
            }
        } else {
            $notCalculated[] = ['fee_type' => $fire['fee_type'], 'note' => 'Không tính trên kỳ này: config billing.fire_insurance.enabled = false.'];
        }

        // Gửi xe - BR-17: số lượng từ FN-02.06, đơn giá từ FN-03.02
        foreach (config('billing.parking') as $vehicleType => $feeType) {
            $quantity = (int) ($vehicles[$vehicleType] ?? 0);
            if ($quantity <= 0) {
                continue;
            }
            $rate = $rates[$feeType] ?? null;
            if ($rate === null) {
                $errors[] = 'Chưa có đơn giá hiệu lực cho "' . self::label($feeType) . '".';
                continue;
            }
            $price = (int) Decimal::toCents((string) $rate['unit_price']);
            $lines[] = [
                'group' => 'parking',
                'fee_type' => $feeType,
                'rate_id' => (int) $rate['id'],
                'rate_effective_date' => $rate['effective_date'],
                'base_quantity' => $quantity * 100,
                'base_unit' => 'xe',
                'unit_price' => $price,
                'amount' => MoneyCalculator::times($quantity, $price),
            ];
        }

        // Mở lại dịch vụ - BR-19: BLOCKED
        $notCalculated[] = ['fee_type' => 'mo_lai_dich_vu', 'note' => (string) config('billing.reconnection_fee.note')];

        return ['lines' => $lines, 'errors' => $errors, 'not_calculated' => $notCalculated];
    }

    private static function areaLine(array &$lines, array &$errors, string $group, string $feeType, int $areaCents, ?array $rate): void
    {
        if ($rate === null) {
            $errors[] = 'Chưa có đơn giá hiệu lực cho "' . self::label($feeType) . '".';

            return;
        }
        $price = (int) Decimal::toCents((string) $rate['unit_price']);
        $lines[] = [
            'group' => $group,
            'fee_type' => $feeType,
            'rate_id' => (int) $rate['id'],
            'rate_effective_date' => $rate['effective_date'],
            'base_quantity' => $areaCents,
            'base_unit' => 'm²',
            'unit_price' => $price,
            'amount' => MoneyCalculator::multiply($areaCents, $price),
        ];
    }

    private static function label(string $feeType): string
    {
        return config('pricing.fee_type')[$feeType]['label'] ?? $feeType;
    }
}
