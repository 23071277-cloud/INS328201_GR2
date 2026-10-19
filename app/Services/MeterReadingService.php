<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\AuditLogRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\MeterReadingRepository;
use App\Repositories\MeterRepository;

/**
 * FN-04.02 Nhập chỉ số thủ công, FN-04.07 Sửa trước khoá kỳ, FN-04.09 Tra cứu.
 * CSĐ do hệ thống xác định (previousReading), CL do server tính - không tin dữ liệu client.
 */
class MeterReadingService extends BaseService
{
    private MeterReadingRepository $readings;
    private BillingPeriodRepository $periods;
    private MeterRepository $meters;
    private MeterReadingValidator $validator;

    public function __construct()
    {
        $this->readings = new MeterReadingRepository();
        $this->periods = new BillingPeriodRepository();
        $this->meters = new MeterRepository();
        $this->validator = new MeterReadingValidator();
    }

    /**
     * CSĐ của công tơ cho kỳ [TL 2.1; SL FN-04.01 "CSĐ = CSC đã xác nhận kỳ trước"]:
     * - chỉ số gần nhất ở kỳ trước ĐÃ XÁC NHẬN -> CSC của nó;
     * - chưa từng có chỉ số -> chỉ số khởi tạo của công tơ ([ĐX] FN-02.05);
     * - chỉ số kỳ trước còn NHÁP -> không xác định được: tài liệu [ĐX] để trống CSĐ + cờ
     *   "cần nhập CSĐ", nhưng schema previous_reading NOT NULL và không có cờ -> BLOCKED B7.
     * @return array{cents:?int,value:?string,source:string,from_period:?string,blocked:?string}
     */
    public function previousReading(array $meter, array $period): array
    {
        $latest = $this->readings->latestBefore((int) $meter['id'], (string) $period['start_date']);

        if ($latest === null) {
            $cents = Decimal::toCents((string) ($meter['initial_reading'] ?? '0')) ?? 0;

            return ['cents' => $cents, 'value' => Decimal::fromCents($cents), 'source' => 'initial', 'from_period' => null, 'blocked' => null];
        }
        if ($latest['status'] !== 'da_xac_nhan') {
            return ['cents' => null, 'value' => null, 'source' => 'blocked', 'from_period' => $latest['period_code'], 'blocked' => sprintf(
                'Chỉ số kỳ %s của công tơ chưa được BQL xác nhận nên chưa xác định được CSĐ. nhưng hệ thống chưa hỗ trợ. Vui lòng duyệt chỉ số kỳ %s trước.',
                $latest['period_code'],
                $latest['period_code']
            )];
        }

        $cents = Decimal::toCents((string) $latest['current_reading']) ?? 0;

        return ['cents' => $cents, 'value' => Decimal::fromCents($cents), 'source' => 'reading', 'from_period' => $latest['period_code'], 'blocked' => null];
    }

    /**
     * Version token chống ghi đè đồng thời (sửa/duyệt): hash nội dung + updated_at.
     * Không dùng riêng updated_at vì độ phân giải giây - 2 thao tác trong cùng giây vẫn phải bị phát hiện.
     */
    public static function versionToken(array $reading): string
    {
        return sha1(json_encode([
            (int) $reading['id'], (string) $reading['current_reading'], (string) $reading['consumption'], (string) $reading['status'],
            (string) ($reading['note'] ?? ''), (string) $reading['source'], (string) ($reading['confirmed_at'] ?? ''), (string) $reading['updated_at'],
        ]));
    }

    /** Thông tin công tơ + CSĐ cho form nhập (FN-04.02, UX: suy ra từ hệ thống). */
    public function entryContext(int $periodId, int $meterId): ?array
    {
        $period = $this->periods->find($periodId);
        $meter = $this->meterWithApartment($meterId);
        if ($period === null || $meter === null) {
            return null;
        }

        return ['period' => $period, 'meter' => $meter, 'previous' => $this->previousReading($meter, $period),
            'existing_id' => $this->readings->existsFor($meterId, $periodId) ? true : null];
    }

    public function pendingMeters(int $periodId): array
    {
        return $this->periods->pendingMeters($periodId);
    }

    /**
     * Worklist màn Nhập chỉ số (UX, chỉ đọc): mỗi công tơ kèm trạng thái ghi số của kỳ.
     * Trạng thái suy ra từ dữ liệu hiện có, KHÔNG thêm trạng thái nghiệp vụ mới:
     *   chua_ghi    - chưa có chỉ số trong kỳ (CSĐ do previousReading xác định; có thể bị chặn B7)
     *   nhap        - đã lưu Nháp = đang chờ BQL duyệt (nghiệp vụ không có bước "gửi duyệt" riêng)
     *   da_xac_nhan - BQL đã duyệt
     * @return list<array<string,mixed>>
     */
    public function worklist(array $period): array
    {
        $rows = [];
        foreach ($this->readings->worklistOfPeriod((int) $period['id']) as $row) {
            if ($row['reading_id'] !== null) {
                $row['state'] = $row['reading_status'] === 'da_xac_nhan' ? 'da_xac_nhan' : 'nhap';
                $row['previous'] = ['value' => Decimal::fromCents(Decimal::toCents((string) $row['previous_reading']) ?? 0), 'source' => 'saved', 'from_period' => null, 'blocked' => null];
            } else {
                $row['state'] = 'chua_ghi';
                $row['previous'] = $this->previousReading($row, $period);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Công tơ "Chưa ghi" nhập được (không bị chặn B7) kế tiếp sau $afterMeterId theo thứ tự worklist; quay vòng; null nếu hết. */
    public function nextPendingMeterId(array $period, int $afterMeterId): ?int
    {
        $worklist = $this->worklist($period);
        $candidates = array_values(array_filter($worklist, static fn (array $r): bool => $r['state'] === 'chua_ghi' && $r['previous']['blocked'] === null));
        if ($candidates === []) {
            return null;
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $worklist);
        $position = array_search($afterMeterId, $ids, true);
        foreach ($candidates as $c) {
            if ($position === false || array_search((int) $c['id'], $ids, true) > $position) {
                return (int) $c['id'];
            }
        }

        return (int) $candidates[0]['id'];
    }

    /** FN-04.02: lưu chỉ số Nháp, nguồn thủ công. @return array{id:int,consumption:string} */
    public function create(int $periodId, int $meterId, array $input): array
    {
        $note = (string) ($input['note'] ?? '');
        (new Validator(['note' => $note]))->maxLength('note', 500, 'Ghi chú tối đa 500 ký tự.')->throwIfFails();

        return $this->transaction($this->readings, function () use ($periodId, $meterId, $input, $note): array {
            $period = $this->periods->findForUpdate($periodId); // tuần tự hoá với khoá kỳ (FN-05)
            if ($period === null) {
                throw ValidationException::withMessage('Kỳ ghi chỉ số không tồn tại.');
            }
            $meter = $this->meterWithApartment($meterId);
            if ($meter === null) {
                throw ValidationException::withMessage('Công tơ không tồn tại.');
            }

            $previous = $this->previousReading($meter, $period);
            $result = $this->validator->validate([
                'period' => $period, 'meter' => $meter, 'current_raw' => $input['current_reading'] ?? null,
                'previous_cents' => $previous['cents'], 'previous_blocked' => $previous['blocked'],
                'duplicate' => $this->readings->existsFor($meterId, $periodId),
            ]);
            $this->throwFirst($result['errors']);

            $id = $this->readings->insertUnlessDuplicate([
                'meter_id' => $meterId,
                'billing_period_id' => $periodId,
                'previous_reading' => $previous['value'],
                'current_reading' => Decimal::fromCents($result['current_cents']),
                'consumption' => Decimal::fromCents($result['consumption_cents']),
                'status' => 'nhap',
                'source' => 'thu_cong',
                'import_batch_id' => null,
                'note' => $this->nullIfEmpty($note),
                'entered_by' => $this->actorId(),
            ]);
            if ($id === null) {
                // 2 request đồng thời cùng công tơ/kỳ: UNIQUE (meter_id, billing_period_id) chặn bản thứ 2
                throw new ValidationException(['_' => ['Đã có chỉ số của công tơ này trong kỳ.']]);
            }

            $this->audit('CREATE_METER_READING', 'meter_reading', $id, null, $this->readings->find($id));

            return ['id' => $id, 'consumption' => Decimal::fromCents($result['consumption_cents'])];
        });
    }

    /**
     * FN-04.07 Sửa chỉ số trước khoá kỳ: CSC mới + lý do bắt buộc (VAL-04.15, lưu vào `note` theo COMMENT schema),
     * validate lại, tính lại CL, nguồn 'dieu_chinh'. Bản Đã xác nhận -> về Nháp chờ duyệt lại
     * ([ĐX] BR-41, [ASSUMPTION J-13]). Chống ghi đè đồng thời bằng version token.
     */
    public function update(int $id, array $input): bool
    {
        $reason = (string) ($input['note'] ?? '');
        $v = new Validator(['note' => $reason]);
        $v->required('note', 'Vui lòng nhập lý do điều chỉnh.')->maxLength('note', 500, 'Lý do tối đa 500 ký tự.')->throwIfFails();

        return $this->transaction($this->readings, function () use ($id, $input, $reason): bool {
            // Thứ tự khoá thống nhất: KỲ trước, CHỈ SỐ sau (như nhập/duyệt/import và tính phí FN-05).
            // Khoá ngược thứ tự gây deadlock với tính phí (ghi charge_tier_details lấy khoá S trên chỉ số qua FK).
            $plain = $this->readings->find($id);
            if ($plain === null) {
                throw ValidationException::withMessage('Chỉ số không tồn tại.');
            }
            $period = $this->periods->findForUpdate((int) $plain['billing_period_id']);
            $current = $this->readings->findForUpdate($id);
            if (($input['version_token'] ?? '') !== self::versionToken($current)) {
                throw ValidationException::withMessage('Dữ liệu đã bị người khác thay đổi sau khi bạn mở form. Vui lòng tải lại trang và nhập lại.');
            }
            $meter = $this->meterWithApartment((int) $current['meter_id']);

            $previousCents = Decimal::toCents((string) $current['previous_reading']);
            $result = $this->validator->validate([
                'period' => $period, 'meter' => $meter, 'current_raw' => $input['current_reading'] ?? null,
                'previous_cents' => $previousCents, 'previous_blocked' => null, 'duplicate' => false,
            ]);
            $this->throwFirst($result['errors']);

            $changes = [
                'current_reading' => Decimal::fromCents($result['current_cents']),
                'consumption' => Decimal::fromCents($result['consumption_cents']),
                'note' => $reason,
            ];
            if ($this->diff($current, $changes) === []) {
                return false;
            }
            $changes['source'] = 'dieu_chinh';
            if ($current['status'] === 'da_xac_nhan') {
                $changes += ['status' => 'nhap', 'confirmed_by' => null, 'confirmed_at' => null]; // BR-41 [ĐX]
            }

            $this->readings->updateById($id, $changes);
            $this->audit('UPDATE_METER_READING', 'meter_reading', $id, array_intersect_key($current, $changes), $changes);

            return true;
        });
    }

    public function find(int $id): ?array
    {
        $reading = $this->readings->findDetailed($id);
        if ($reading !== null) {
            $reading['version_token'] = self::versionToken($reading);
        }

        return $reading;
    }

    public function detail(int $id): ?array
    {
        $reading = $this->find($id);
        if ($reading !== null) {
            $reading['history'] = (new AuditLogRepository())->forEntity('meter_reading', (string) $id);
        }

        return $reading;
    }

    public function list(array $filters, int $page): array
    {
        foreach (['period_id', 'zone_id'] as $key) {
            $filters[$key] = $this->isPositiveInt($filters[$key]) ? $filters[$key] : '';
        }
        $filters['utility_type'] = array_key_exists($filters['utility_type'], config('master_data.utility_type')) ? $filters['utility_type'] : '';
        $filters['status'] = array_key_exists($filters['status'], config('readings.reading_status')) ? $filters['status'] : '';
        $filters['source'] = array_key_exists($filters['source'], config('readings.reading_source')) ? $filters['source'] : '';

        return $this->readings->search($filters, $page, (int) config('master_data.per_page', 20));
    }

    /** Công tơ kèm trạng thái căn hộ (VAL-04.05). */
    public function meterWithApartment(int $meterId): ?array
    {
        $meter = $this->meters->findDetailed($meterId);

        return $meter;
    }

    /** Lỗi đầu tiên -> ValidationException; lỗi CSC < CSĐ kèm khoá 'meter_reset' cho giao diện FN-04.06. */
    private function throwFirst(array $errors): void
    {
        if ($errors === []) {
            return;
        }
        $first = $errors[0];
        $payload = [$first['field'] => [$first['message']]];
        if ($first['meter_reset']) {
            $payload['meter_reset'] = ['1'];
        }

        throw new ValidationException($payload);
    }
}
