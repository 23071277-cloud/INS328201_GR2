<?php

declare(strict_types=1);

namespace App\Services;

/**
 * FN-05.04 Tổng hợp phát sinh, nợ cũ, tổng phải thu - BR-18, BR-20.
 *   tiền tiện ích       = Σ điện (TT + VAT) + Σ nước (TT)
 *   phí cố định         = DV + BHCN + gửi xe
 *   tổng phát sinh kỳ   = tiền tiện ích + phí cố định          (apartment_charges.total_arising)
 *   tổng phải thu       = tổng phát sinh + nợ cũ               (apartment_charges.total_due, BR-18)
 * Mọi tổng là phép cộng các dòng ĐÃ làm tròn -> luôn bằng breakdown (lệch 0 đ, BR-20).
 * Nước có VAT ≠ 0: schema không có cột VAT nước -> lỗi DESIGN GAP (J-07), không cộng ngầm vào tiền nước.
 */
final class ChargeAggregationService
{
    /**
     * @param array<int,array> $meters kết quả từng công tơ (utility_type, consumption, subtotal, vat, total)
     * @param array<int,array> $fees   dòng phí (group, amount)
     * @return array{columns: array<string,int>, totals: array<string,int>, errors: string[]}
     */
    public static function aggregate(array $meters, array $fees, int $oldDebtCents): array
    {
        $errors = [];
        $c = [
            'electricity_consumption' => 0, 'electricity_amount' => 0, 'electricity_vat_amount' => 0,
            'water_consumption' => 0, 'water_amount' => 0,
            'service_fee_amount' => 0, 'fire_insurance_amount' => 0, 'parking_fee_amount' => 0,
        ];

        foreach ($meters as $m) {
            if ($m['utility_type'] === 'dien') {
                $c['electricity_consumption'] += $m['consumption'];
                $c['electricity_amount'] += $m['subtotal'];
                $c['electricity_vat_amount'] += $m['vat'];
            } else {
                if ($m['vat'] !== 0) {
                    $errors[] = 'Biểu giá nước có VAT nhưng hệ thống chưa lưu VAT nước — chưa tính được, vui lòng liên hệ quản trị.';
                }
                $c['water_consumption'] += $m['consumption'];
                $c['water_amount'] += $m['subtotal'];
            }
        }

        $column = ['service' => 'service_fee_amount', 'fire_insurance' => 'fire_insurance_amount', 'parking' => 'parking_fee_amount'];
        foreach ($fees as $fee) {
            $c[$column[$fee['group']]] += $fee['amount'];
        }

        $utility = $c['electricity_amount'] + $c['electricity_vat_amount'] + $c['water_amount'];
        $fixed = $c['service_fee_amount'] + $c['fire_insurance_amount'] + $c['parking_fee_amount'];
        $arising = $utility + $fixed;

        $c['total_arising'] = $arising;
        $c['old_debt_amount'] = $oldDebtCents;
        $c['total_due'] = $arising + $oldDebtCents;
        if (max($c) > MoneyCalculator::MAX_CENTS) {
            $errors[] = 'Tổng tiền vượt giới hạn lưu trữ DECIMAL(14,2) — kiểm tra lại chỉ số / đơn giá.';
        }

        return [
            'columns' => $c,
            'totals' => [
                'utility_charge' => $utility,
                'fixed_fee' => $fixed,
                'old_debt' => $oldDebtCents,
                'current_period_total' => $arising,
                'total_payable' => $arising + $oldDebtCents,
            ],
            'errors' => $errors,
        ];
    }

    /** Tách tổng từ các cột đã lưu (hiển thị bản tính cũ / seed). Đầu vào: cột => xu. */
    public static function totalsFromColumns(array $c): array
    {
        $utility = $c['electricity_amount'] + $c['electricity_vat_amount'] + $c['water_amount'];
        $fixed = $c['service_fee_amount'] + $c['fire_insurance_amount'] + $c['parking_fee_amount'];

        return [
            'utility_charge' => $utility,
            'fixed_fee' => $fixed,
            'old_debt' => $c['old_debt_amount'],
            'current_period_total' => $c['total_arising'],
            'total_payable' => $c['total_due'],
            // kiểm tra số học trên dữ liệu đã lưu (BR-18, BR-20)
            'consistent' => $utility + $fixed === $c['total_arising'] && $c['total_arising'] + $c['old_debt_amount'] === $c['total_due'],
        ];
    }
}
