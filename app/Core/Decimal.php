<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Số thập phân 2 chữ số (DECIMAL(12,2) của schema) biểu diễn bằng số nguyên "xu" (×100)
 * để cộng/trừ chính xác, không dùng float (CL = CSC − CSĐ phải đúng tuyệt đối).
 */
final class Decimal
{
    public const MAX_CENTS = 999999999999; // 9.999.999.999,99

    /** "123", "123.4", "123,45" -> 12345; không hợp lệ / âm / quá lớn -> null. */
    public static function toCents(?string $value): ?int
    {
        $value = str_replace(' ', '', (string) $value);
        if (!str_contains($value, '.') && substr_count($value, ',') === 1) {
            $value = str_replace(',', '.', $value);
        }
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $cents <= self::MAX_CENTS ? $cents : null;
    }

    public static function isNegative(?string $value): bool
    {
        return (bool) preg_match('/^\s*-\s*\d/', (string) $value);
    }

    /** 12345 -> "123.45"; -500 -> "-5.00". */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
