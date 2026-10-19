<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Decimal;
use InvalidArgumentException;

/**
 * FN-05.02 - tính tiền MỘT công tơ theo MỘT phiên bản biểu giá (hàm thuần, không đọc DB).
 *
 *  Sinh hoạt (bậc thang, BR-13/14) - quy ước Schema Review A1, nửa khoảng [tier_from, tier_to):
 *      to  = tier_to ?? CL
 *      qty = max(0, min(CL, to) − tier_from)        // KHÔNG +1
 *      amount = round(qty × đơn giá)                // MoneyCalculator (J-15)
 *  Kinh doanh (đồng giá): 1 dòng tier_order=1, [0, NULL), qty = CL, giá = business_unit_price.
 *  VAT (theo công tơ) = round(TT trước thuế × vat_percent); vat_percent NULL = không tách VAT (J-07).
 *
 * Mọi số là số nguyên xu (×100). Dữ liệu bậc sai (hở/chồng/không mở cuối) -> InvalidArgumentException:
 * KHÔNG tính tiếp trên biểu giá hỏng.
 */
final class TariffCalculator
{
    /**
     * @param int   $consumptionCents CL ×100 (không âm)
     * @param array $tariff  dòng tariffs (usage_type, vat_percent, business_unit_price)
     * @param array $tiers   dòng tariff_tiers theo tier_order (bỏ qua với kinh doanh)
     * @return array{mode:string, lines:array<int,array>, subtotal:int, vat_percent:?int, vat:int, total:int}
     */
    public static function calculate(int $consumptionCents, array $tariff, array $tiers): array
    {
        if ($consumptionCents < 0) {
            throw new InvalidArgumentException('CL âm không được tính tiền.');
        }

        $lines = $tariff['usage_type'] === 'kinh_doanh'
            ? [self::flatLine($consumptionCents, $tariff)]
            : self::tierLines($consumptionCents, $tiers);

        $subtotal = array_sum(array_column($lines, 'amount'));
        $vatPercent = self::cents($tariff['vat_percent'] ?? null);
        $vat = $vatPercent === null ? 0 : MoneyCalculator::percent($subtotal, $vatPercent);

        return [
            'mode' => $tariff['usage_type'] === 'kinh_doanh' ? 'flat' : 'tiered',
            'lines' => $lines,
            'subtotal' => $subtotal,
            'vat_percent' => $vatPercent,
            'vat' => $vat,
            'total' => $subtotal + $vat,
        ];
    }

    private static function flatLine(int $consumptionCents, array $tariff): array
    {
        $price = self::cents($tariff['business_unit_price'] ?? null);
        if ($price === null || $price <= 0) {
            throw new InvalidArgumentException('Biểu giá kinh doanh thiếu đơn giá đồng giá.');
        }

        return [
            'tier_order' => 1,
            'tier_from' => 0,
            'tier_to' => null,
            'quantity' => $consumptionCents,
            'unit_price' => $price,
            'amount' => MoneyCalculator::multiply($consumptionCents, $price),
        ];
    }

    private static function tierLines(int $cl, array $tiers): array
    {
        if ($tiers === []) {
            throw new InvalidArgumentException('Biểu giá bậc thang không có bậc nào.');
        }
        usort($tiers, static fn (array $a, array $b): int => (int) $a['tier_order'] <=> (int) $b['tier_order']);

        $lines = [];
        $expectedFrom = 0;
        $last = count($tiers) - 1;
        foreach ($tiers as $i => $tier) {
            $from = self::cents($tier['tier_from']);
            $to = self::cents($tier['tier_to'] ?? null);
            $price = self::cents($tier['unit_price']);
            // VAL-03.02/03.03 kiểm lại ở thời điểm tính (dữ liệu có thể đến từ seed / sửa tay DB)
            if ($from !== $expectedFrom || ($to === null) !== ($i === $last) || ($to !== null && $to <= $from) || $price === null || $price <= 0) {
                throw new InvalidArgumentException('Bậc giá không liên tục / không mở cuối — không tính.');
            }
            $expectedFrom = $to ?? $from;

            $quantity = max(0, min($cl, $to ?? $cl) - $from);
            if ($quantity === 0) {
                continue; // bậc không có sản lượng: không sinh dòng (như dữ liệu seed)
            }
            $lines[] = [
                'tier_order' => (int) $tier['tier_order'],
                'tier_from' => $from,
                'tier_to' => $to,
                'quantity' => $quantity,
                'unit_price' => $price,
                'amount' => MoneyCalculator::multiply($quantity, $price),
            ];
        }

        return $lines;
    }

    /** "1806.00" -> 180600; null/"" -> null. Giá trị DECIMAL(…,2) từ DB. */
    private static function cents($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        $cents = Decimal::toCents((string) $value);
        if ($cents === null) {
            throw new InvalidArgumentException('Giá trị số không hợp lệ trong biểu giá: ' . $value);
        }

        return $cents;
    }
}
