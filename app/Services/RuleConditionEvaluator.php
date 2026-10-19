<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * FN-07.02 - so sánh giá trị đo với ngưỡng theo toán tử (generic, không theo tên rule).
 * Giá trị dạng chuỗi DECIMAL; so sánh bằng bcmath (không float). Giá trị null (vd % không xác định) -> KHÔNG đánh giá.
 * Schema alert_rules.operator hiện chỉ có gt/gte; lt/lte/eq có sẵn ở đây nhưng không được cấu hình được.
 */
final class RuleConditionEvaluator
{
    public const OPERATORS = ['gt', 'gte', 'lt', 'lte', 'eq'];

    /** @return ?bool null = không đánh giá được (thiếu giá trị) */
    public static function matches(string $operator, ?string $value, string $threshold): ?bool
    {
        if ($value === null) {
            return null;
        }
        $cmp = bccomp($value, $threshold, 4);

        return match ($operator) {
            'gt' => $cmp > 0,
            'gte' => $cmp >= 0,
            'lt' => $cmp < 0,
            'lte' => $cmp <= 0,
            'eq' => $cmp === 0,
            default => throw new InvalidArgumentException('Toán tử không hỗ trợ: ' . $operator),
        };
    }

    /** "30.0000" -> "30"; dùng cho câu giải thích. */
    public static function plain(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }
        $v = rtrim(rtrim($value, '0'), '.');

        return $v === '' || $v === '-' ? '0' : $v;
    }
}
