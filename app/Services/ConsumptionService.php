<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Decimal;
use App\Repositories\ApartmentChargeRepository;

/**
 * FN-05.01 Tính lượng tiêu thụ - BR-12 CL = CSC − CSĐ.
 * CL do server tính và lưu ở FN-04 (MeterReadingService / import). FN-05 KHÔNG nhận CL từ client:
 * đọc từ DB, kiểm lại CSC − CSĐ = CL (phát hiện dữ liệu sửa tay ngoài ứng dụng) và chỉ dùng
 * chỉ số Đã xác nhận (BR-11). Thay đồng hồ (FN-04.06) BLOCKED B5/J-11.
 */
class ConsumptionService
{
    private ApartmentChargeRepository $charges;

    public function __construct(?ApartmentChargeRepository $charges = null)
    {
        $this->charges = $charges ?? new ApartmentChargeRepository();
    }

    /**
     * @return array{readings: array<int,array>, errors: string[]} readings kèm 'consumption_cents'
     */
    public function billableReadings(int $apartmentId, int $periodId): array
    {
        $errors = [];
        $readings = [];

        foreach ($this->charges->metersWithoutReading($apartmentId, $periodId) as $meter) {
            $errors[] = "Công tơ {$meter['meter_code']} đang hoạt động nhưng chưa có chỉ số trong kỳ.";
        }

        foreach ($this->charges->readingsOf($apartmentId, $periodId) as $reading) {
            $check = self::verify($reading);
            if ($check !== null) {
                $errors[] = "Công tơ {$reading['meter_code']}: {$check}";
                continue;
            }
            $reading['consumption_cents'] = (int) Decimal::toCents((string) $reading['consumption']);
            $readings[] = $reading;
        }

        return ['readings' => $readings, 'errors' => $errors];
    }

    /** null = dùng được để tính phí; ngược lại là lý do. */
    public static function verify(array $reading): ?string
    {
        if ($reading['status'] !== 'da_xac_nhan') {
            return 'chỉ số còn Nháp, chưa được BQL duyệt — không dùng để tính phí.';
        }
        if ((int) ($reading['is_meter_changed'] ?? 0) === 1) {
            return 'chỉ số thay công tơ — chưa hỗ trợ tính phí.';
        }
        $previous = Decimal::toCents((string) $reading['previous_reading']);
        $current = Decimal::toCents((string) $reading['current_reading']);
        $consumption = Decimal::toCents((string) $reading['consumption']);
        if ($previous === null || $current === null || $consumption === null) {
            return 'chỉ số không hợp lệ (âm hoặc không phải số).';
        }
        if ($current - $previous !== $consumption) {
            return 'CL lưu (' . $reading['consumption'] . ') ≠ CSC − CSĐ (' . Decimal::fromCents($current - $previous) . ') — dữ liệu không nhất quán.';
        }

        return null;
    }
}
