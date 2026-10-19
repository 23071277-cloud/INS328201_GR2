<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\ApartmentRepository;
use App\Repositories\MeterRepository;

/**
 * FN-02.05 Quản lý đồng hồ đo | [TL] FR-03 (EN) meter ID/utility type/status, 2.2, BR-06, BR-08.
 * VAL-02.05 mã trùng; VAL-02.06 thiếu loại tiện ích/loại sử dụng; VAL-02.07 căn hộ đã ngừng.
 * Phân quyền: [NEED BA CONFIRMATION J-02] tạm áp như căn hộ.
 * Không xoá công tơ (D1); công tơ đã có chỉ số khoá các trường ảnh hưởng lịch sử (D8).
 */
class MeterService extends BaseService
{
    /** Trường bị khoá khi công tơ đã có chỉ số (D8) => nhãn hiển thị. */
    private const LOCKED_WHEN_HAS_READINGS = [
        'apartment_id' => 'căn hộ',
        'utility_type' => 'loại tiện ích',
        'usage_type' => 'loại sử dụng',
        'initial_reading' => 'chỉ số khởi tạo',
    ];

    private MeterRepository $meters;
    private ApartmentRepository $apartments;

    public function __construct()
    {
        $this->meters = new MeterRepository();
        $this->apartments = new ApartmentRepository();
    }

    public function list(array $filters, int $page): array
    {
        foreach (['record_status' => 'status', 'utility_type' => 'utility_type', 'usage_type' => 'usage_type'] as $group => $key) {
            $filters[$key] = array_key_exists($filters[$key], config('master_data.' . $group)) ? $filters[$key] : '';
        }
        foreach (['building_id', 'apartment_id'] as $key) {
            $filters[$key] = $this->isPositiveInt($filters[$key]) ? $filters[$key] : '';
        }

        return $this->meters->search($filters, $page, (int) config('master_data.per_page', 20));
    }

    public function find(int $id): ?array
    {
        $meter = $this->meters->findDetailed($id);
        if ($meter !== null) {
            $meter['reading_count'] = $this->meters->countReadings($id);
        }

        return $meter;
    }

    public function create(array $input): int
    {
        return $this->transaction($this->meters, function () use ($input): int {
            $data = $this->validate($input, null);
            $id = $this->meters->insertRow($data + ['status' => 'hoat_dong']);
            $this->audit('create', 'meter', $id, null, $this->meters->find($id));

            return $id;
        });
    }

    public function update(int $id, array $input): bool
    {
        return $this->transaction($this->meters, function () use ($id, $input): bool {
            $current = $this->lockOrFail($id);
            $this->assertNotStale($current, $input['updated_at'] ?? null);

            $changes = $this->diff($current, $this->validate($input, $current));
            if ($changes === []) {
                return false;
            }

            $this->meters->updateById($id, $changes);
            $this->audit('update', 'meter', $id, array_intersect_key($current, $changes), $changes);

            return true;
        });
    }

    /** Chỉ ngừng, không xoá; chỉ số (FN-04) của công tơ được giữ nguyên. */
    public function deactivate(int $id): int
    {
        return $this->transaction($this->meters, function () use ($id): int {
            $current = $this->lockOrFail($id);
            if ($current['status'] === 'ngung') {
                throw ValidationException::withMessage('Công tơ đã ở trạng thái Ngừng.');
            }

            $readingCount = $this->meters->countReadings($id);
            $this->meters->updateById($id, ['status' => 'ngung']);
            $this->audit(
                'deactivate',
                'meter',
                $id,
                ['status' => $current['status']],
                ['status' => 'ngung', 'readings_kept' => $readingCount]
            );

            return $readingCount;
        });
    }

    /** Mã công tơ đang có (chỉ để màn Thêm gợi ý mã chưa trùng; validate UNIQUE vẫn ở validate()). */
    public function existingCodes(): array
    {
        return $this->meters->allCodes();
    }

    private function lockOrFail(int $id): array
    {
        $row = $this->meters->findForUpdate($id);
        if ($row === null) {
            throw ValidationException::withMessage('Công tơ không tồn tại.');
        }

        return $row;
    }

    private function validate(array $input, ?array $current): array
    {
        $data = [
            'apartment_id' => (string) ($input['apartment_id'] ?? ''),
            'meter_code' => (string) ($input['meter_code'] ?? ''),
            'utility_type' => (string) ($input['utility_type'] ?? ''),
            'usage_type' => (string) ($input['usage_type'] ?? ''),
            'display_label' => (string) ($input['display_label'] ?? ''),
            'initial_reading' => $this->normalizeDecimal($input['initial_reading'] ?? ''),
        ];

        $v = new Validator($data);
        $v->required('apartment_id', 'Vui lòng chọn căn hộ.')
            ->required('meter_code', 'Vui lòng nhập mã công tơ.')
            ->maxLength('meter_code', 30, 'Mã công tơ tối đa 30 ký tự.')
            ->pattern('meter_code', self::CODE_REGEX, self::CODE_MESSAGE)
            ->required('utility_type', 'Vui lòng chọn loại tiện ích và loại sử dụng.') // VAL-02.06
            ->in('utility_type', array_keys(config('master_data.utility_type')), 'Loại tiện ích không hợp lệ (chỉ Điện hoặc Nước).')
            ->required('usage_type', 'Vui lòng chọn loại tiện ích và loại sử dụng.') // VAL-02.06
            ->in('usage_type', array_keys(config('master_data.usage_type')), 'Loại sử dụng không hợp lệ (chỉ Sinh hoạt hoặc Kinh doanh).')
            ->maxLength('display_label', 50, 'Nhãn hiển thị tối đa 50 ký tự.')
            ->required('initial_reading', 'Vui lòng nhập chỉ số khởi tạo.') // D9 [ĐX]
            ->decimal('initial_reading', 2, 0, 9999999999.99, false, 'Chỉ số khởi tạo phải là số ≥ 0 (tối đa 2 chữ số thập phân).');

        $apartmentId = $this->isPositiveInt($data['apartment_id']) ? (int) $data['apartment_id'] : 0;
        $apartmentChanged = $current === null || (int) $current['apartment_id'] !== $apartmentId;

        if (!$v->hasError('apartment_id')) {
            $apartment = $apartmentId > 0 ? $this->apartments->find($apartmentId) : null;
            if ($apartment === null) {
                $v->addError('apartment_id', 'Căn hộ không tồn tại.');
            } elseif ($apartmentChanged && $apartment['status'] !== 'hoat_dong') {
                $v->addError('apartment_id', 'Căn hộ không còn hoạt động.'); // VAL-02.07
            }
        }

        if (!$v->hasError('meter_code')
            && $this->meters->codeExists($data['meter_code'], $current !== null ? (int) $current['id'] : null)) {
            $v->addError('meter_code', 'Mã công tơ đã tồn tại.'); // VAL-02.05
        }

        $result = [
            'apartment_id' => $apartmentId,
            'meter_code' => $data['meter_code'],
            'utility_type' => $data['utility_type'],
            'usage_type' => $data['usage_type'],
            'display_label' => $this->nullIfEmpty($data['display_label']),
            'initial_reading' => $v->hasError('initial_reading') ? $data['initial_reading'] : number_format((float) $data['initial_reading'], 2, '.', ''),
        ];

        // D8: công tơ đã có chỉ số -> không đổi các trường ảnh hưởng lịch sử tiêu thụ/biểu giá.
        if ($current !== null && $this->meters->countReadings((int) $current['id']) > 0) {
            foreach (self::LOCKED_WHEN_HAS_READINGS as $field => $label) {
                if (!$v->hasError($field) && (string) $current[$field] !== (string) $result[$field]) {
                    $v->addError($field, "Không thể đổi {$label} vì công tơ đã có dữ liệu chỉ số.");
                }
            }
        }

        $v->throwIfFails();

        return $result;
    }
}
