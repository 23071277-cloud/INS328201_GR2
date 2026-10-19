<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Tiền và lượng FN-05 dưới dạng số nguyên "xu" (×100, như App\Core\Decimal) - KHÔNG dùng float.
 * Phép nhân dùng bcmath (không tràn số nguyên). MỌI chỗ làm tròn đi qua round() theo
 * config/billing.php 'rounding' [ASSUMPTION J-15] -> BA đổi quy tắc chỉ sửa cấu hình.
 */
final class MoneyCalculator
{
    /** DECIMAL(14,2) lớn nhất của apartment_charges / charge_tier_details. */
    public const MAX_CENTS = 99999999999999;

    /** lượng (xu) × đơn giá (xu) -> tiền (xu), làm tròn theo quy tắc. Dùng cho dòng bậc, DT × đơn giá. */
    public static function multiply(int $quantityCents, int $priceCents): int
    {
        self::assertNonNegative($quantityCents, $priceCents);

        // xu × xu = đơn vị 10^-4 đồng
        return self::round(bcmul((string) $quantityCents, (string) $priceCents, 0), 4);
    }

    /** số nguyên (vd số xe) × đơn giá (xu) - chính xác, không cần làm tròn. */
    public static function times(int $count, int $priceCents): int
    {
        self::assertNonNegative($count, $priceCents);

        return self::check(bcmul((string) $count, (string) $priceCents, 0));
    }

    /** tiền (xu) × phần trăm (xu của %, vd 8.00% = 800) -> tiền (xu), làm tròn. Dùng cho VAT. */
    public static function percent(int $amountCents, int $percentCents): int
    {
        self::assertNonNegative($amountCents, $percentCents);

        // xu × (%·100) / 100 (phần trăm) = đơn vị 10^-6 đồng
        return self::round(bcmul((string) $amountCents, (string) $percentCents, 0), 6);
    }

    /** tổng (xu) ÷ số lượng -> xu, làm tròn (BR-35 chi phí TB/căn). */
    public static function divide(int $amountCents, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        self::assertNonNegative($amountCents);
        // giữ 6 chữ số phụ rồi làm tròn theo quy tắc
        $scaled = bcdiv(bcmul((string) $amountCents, '10000', 0), (string) $count, 0);

        return self::round($scaled, 6);
    }

    /**
     * Làm tròn giá trị nguyên ở đơn vị 10^-$fromScale ĐỒNG về số xu, theo quy tắc cấu hình
     * (scale 2 = tới xu, 0 = tới đồng; half_up). Giá trị không âm.
     */
    public static function round(string $value, int $fromScale): int
    {
        $scale = self::scale();
        if ($fromScale <= $scale) {
            return self::check(bcmul($value, bcpow('10', (string) (2 - $fromScale), 0), 0));
        }
        $step = bcpow('10', (string) ($fromScale - $scale), 0);
        $units = match (self::mode()) {
            'half_up' => bcdiv(bcadd($value, bcdiv($step, '2', 0), 0), $step, 0),
            'down' => bcdiv($value, $step, 0),
            default => throw new RuntimeException('Quy tắc làm tròn không hỗ trợ: ' . self::mode()),
        };

        return self::check(bcmul($units, bcpow('10', (string) (2 - $scale), 0), 0));
    }

    /** Giá trị DECIMAL(…,2) đọc từ DB ("1950058.00", "-5.5") -> xu. Không hợp lệ -> RuntimeException. */
    public static function fromDb($value): int
    {
        $value = trim((string) $value);
        if (!preg_match('/^(-?)(\d{1,14})(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new RuntimeException('Giá trị tiền không hợp lệ: ' . $value);
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    public static function scale(): int
    {
        $scale = (int) config('billing.rounding.scale', 2);

        return max(0, min(2, $scale));
    }

    public static function mode(): string
    {
        return (string) config('billing.rounding.mode', 'half_up');
    }

    /** Mô tả quy tắc để ghi vào snapshot / hiển thị. */
    public static function describe(): string
    {
        return (self::scale() === 2 ? 'tới xu (0,01 đ)' : (self::scale() === 1 ? 'tới hào (0,1 đ)' : 'tới đồng')) . ', ' . self::mode();
    }

    private static function check(string $value): int
    {
        if (bccomp($value, (string) self::MAX_CENTS, 0) > 0) {
            throw new RuntimeException('Số tiền vượt giới hạn DECIMAL(14,2).');
        }

        return (int) $value;
    }

    private static function assertNonNegative(int ...$values): void
    {
        foreach ($values as $v) {
            if ($v < 0) {
                throw new RuntimeException('Giá trị âm không hợp lệ trong tính tiền.');
            }
        }
    }
}
