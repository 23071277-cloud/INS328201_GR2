<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Decimal;
use App\Repositories\ConsumptionAnalyticsRepository;

/**
 * FN-06 - đo tiêu thụ của một phạm vi trong một kỳ, một tiện ích (R10). Dùng chung cho baseline, so sánh,
 * theo dõi mục tiêu (và FN-07 sau này) để không lặp logic.
 *   giá trị = Σ CL chỉ số ĐÃ XÁC NHẬN (BR-11) của mọi công tơ thuộc phạm vi - tách theo tiện ích.
 *   data_status: ok | no_data (không có chỉ số) | incomplete (còn Nháp, hoặc kỳ chưa khoá còn công tơ chưa
 *   ghi số) | meter_changed (B5). Chỉ 'ok' mới có giá trị - KHÔNG coi thiếu dữ liệu là 0.
 * Số lượng biểu diễn bằng số nguyên xu (×100), không float.
 */
class ScopeConsumptionService
{
    private ConsumptionAnalyticsRepository $analytics;

    public function __construct(?ConsumptionAnalyticsRepository $analytics = null)
    {
        $this->analytics = $analytics ?? new ConsumptionAnalyticsRepository();
    }

    /** @return array{status:string, value:?int, confirmed:int, drafts:int, missing:int} */
    public function measure(string $scopeType, int $scopeId, string $utility, array $period): array
    {
        return $this->measureAll($scopeType, $utility, $period, $scopeId)[$scopeId] ?? self::result(null, 0);
    }

    /**
     * Đo cho mọi thực thể có dữ liệu của một loại phạm vi (thực thể không có trong kết quả = no_data).
     * @return array<int, array{status:string, value:?int, confirmed:int, drafts:int, missing:int}>
     */
    public function measureAll(string $scopeType, string $utility, array $period, ?int $scopeId = null): array
    {
        $periodId = (int) $period['id'];
        $sums = $this->analytics->consumptionByScope($scopeType, $utility, $periodId, $scopeId);
        // kỳ đã khoá: FN-05.07 đã bảo đảm không còn công tơ chưa ghi số lúc khoá; công tơ tạo sau không tính
        $missing = $period['status'] === 'da_khoa' ? [] : $this->analytics->missingByScope($scopeType, $utility, $periodId, $scopeId);

        $out = [];
        foreach (array_unique(array_merge(array_keys($sums), array_keys($missing))) as $id) {
            $out[(int) $id] = self::result($sums[$id] ?? null, $missing[$id] ?? 0);
        }

        return $out;
    }

    public static function emptyResult(): array
    {
        return self::result(null, 0);
    }

    private static function result(?array $sum, int $missing): array
    {
        $confirmed = $sum['confirmed_count'] ?? 0;
        $drafts = $sum['draft_count'] ?? 0;
        $status = match (true) {
            ($sum['changed_count'] ?? 0) > 0 => 'meter_changed',
            $drafts > 0 || $missing > 0 => 'incomplete',
            $confirmed === 0 => 'no_data',
            default => 'ok',
        };

        return [
            'status' => $status,
            'value' => $status === 'ok' ? (int) Decimal::toCents((string) $sum['confirmed_sum']) : null,
            'confirmed' => $confirmed,
            'drafts' => $drafts,
            'missing' => $missing,
        ];
    }

    /** a × 10000 / b làm tròn half-up (đối xứng theo dấu) -> phần trăm 2 chữ số dạng chuỗi; b = 0 -> null (R11). */
    public static function percent(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }
        $scaled = bcdiv(bcmul((string) $numerator, '1000000', 0), (string) $denominator, 0); // ×10^6 / b = % × 10^4
        $negative = str_starts_with($scaled, '-');
        $abs = ltrim($scaled, '-');
        $hundredths = bcdiv(bcadd($abs, '50', 0), '100', 0); // % × 100, half-up
        $value = Decimal::fromCents((int) $hundredths);

        return ($negative && $hundredths !== '0' ? '-' : '') . $value;
    }

    /** Số xu -> chuỗi DECIMAL ("343.00"); null giữ null. */
    public static function fmt(?int $cents): ?string
    {
        return $cents === null ? null : Decimal::fromCents($cents);
    }
}
