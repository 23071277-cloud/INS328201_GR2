<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\ApartmentRepository;
use App\Repositories\BuildingRepository;
use App\Repositories\MeterRepository;
use App\Repositories\ParkingVehicleRepository;
use App\Repositories\ResidentRepository;
use App\Repositories\ZoneRepository;

/**
 * FN-02.03 Quản lý căn hộ | [TL] FR-03, 2.1, 2.3, BR-05, BR-10.
 * VAL-02.01 thiếu mã/diện tích; VAL-02.02 DT <= 0 hoặc không phải số;
 * VAL-02.03 mã trùng (trong tòa - UNIQUE building_id+code); không xoá cứng (BR-05, D1).
 * occupancy_status: [ASSUMPTION A-12 - J-16] BQL khai báo tay, dùng cho BR-15.
 */
class ApartmentService extends BaseService
{
    private ApartmentRepository $apartments;
    private BuildingRepository $buildings;
    private ZoneRepository $zones;

    public function __construct()
    {
        $this->apartments = new ApartmentRepository();
        $this->buildings = new BuildingRepository();
        $this->zones = new ZoneRepository();
    }

    public function list(array $filters, int $page): array
    {
        $filters['status'] = array_key_exists($filters['status'], config('master_data.record_status')) ? $filters['status'] : '';
        $filters['occupancy_status'] = array_key_exists($filters['occupancy_status'], config('master_data.occupancy_status')) ? $filters['occupancy_status'] : '';
        foreach (['building_id', 'zone_id'] as $key) {
            $filters[$key] = $this->isPositiveInt($filters[$key]) ? $filters[$key] : '';
        }

        return $this->apartments->search($filters, $page, (int) config('master_data.per_page', 20));
    }

    public function find(int $id): ?array
    {
        return $this->apartments->findDetailed($id);
    }

    /** Chi tiết căn hộ kèm công tơ, chủ hộ/khách thuê, phương tiện (FN-02.04/05/06). */
    public function detail(int $id): ?array
    {
        $apartment = $this->apartments->findDetailed($id);
        if ($apartment === null) {
            return null;
        }

        $apartment['meters'] = (new MeterRepository())->listByApartment($id);
        $apartment['residents'] = (new ResidentRepository())->listByApartment($id);
        $apartment['vehicles'] = (new ParkingVehicleRepository())->mapByApartment($id);

        return $apartment;
    }

    public function options(bool $activeOnly): array
    {
        return $this->apartments->options($activeOnly);
    }

    public function hasMeters(int $id): bool
    {
        return $this->apartments->countMeters($id) > 0;
    }

    public function create(array $input): int
    {
        return $this->transaction($this->apartments, function () use ($input): int {
            $data = $this->validate($input, null);
            $id = $this->apartments->insertRow($data + ['status' => 'hoat_dong']);
            $this->audit('create', 'apartment', $id, null, $this->apartments->find($id));

            return $id;
        });
    }

    public function update(int $id, array $input): bool
    {
        return $this->transaction($this->apartments, function () use ($id, $input): bool {
            $current = $this->lockOrFail($id);
            $this->assertNotStale($current, $input['updated_at'] ?? null);

            $changes = $this->diff($current, $this->validate($input, $current));
            if ($changes === []) {
                return false;
            }

            $this->apartments->updateById($id, $changes);
            $this->audit('update', 'apartment', $id, array_intersect_key($current, $changes), $changes);

            return true;
        });
    }

    /**
     * [TL] FR-03: Deactivate. Còn công tơ đang hoạt động -> chặn
     * (tài liệu [ĐX] "hỏi có ngừng luôn các công tơ" - chưa làm, D4 NEED BA CONFIRMATION).
     */
    public function deactivate(int $id): void
    {
        $this->transaction($this->apartments, function () use ($id): void {
            $current = $this->lockOrFail($id);
            if ($current['status'] === 'ngung') {
                throw ValidationException::withMessage('Căn hộ đã ở trạng thái Ngừng.');
            }

            $activeMeters = $this->apartments->countActiveMeters($id);
            if ($activeMeters > 0) {
                throw ValidationException::withMessage(
                    "Không thể ngừng căn hộ: còn {$activeMeters} công tơ đang hoạt động. Vui lòng ngừng các công tơ trước."
                );
            }

            $this->apartments->updateById($id, ['status' => 'ngung']);
            $this->audit('deactivate', 'apartment', $id, ['status' => $current['status']], ['status' => 'ngung']);
        });
    }

    private function lockOrFail(int $id): array
    {
        $row = $this->apartments->findForUpdate($id);
        if ($row === null) {
            throw ValidationException::withMessage('Căn hộ không tồn tại.');
        }

        return $row;
    }

    private function validate(array $input, ?array $current): array
    {
        $data = [
            'building_id' => (string) ($input['building_id'] ?? ''),
            'zone_id' => (string) ($input['zone_id'] ?? ''),
            'code' => (string) ($input['code'] ?? ''),
            'area' => $this->normalizeDecimal($input['area'] ?? ''),
            'occupancy_status' => (string) ($input['occupancy_status'] ?? ''),
        ];

        $v = new Validator($data);
        $v->required('building_id', 'Vui lòng chọn tòa nhà.')
            ->required('code', 'Vui lòng nhập mã căn hộ.') // VAL-02.01
            ->maxLength('code', 30, 'Mã căn hộ tối đa 30 ký tự.')
            ->pattern('code', self::CODE_REGEX, self::CODE_MESSAGE)
            ->required('area', 'Vui lòng nhập diện tích.') // VAL-02.01
            ->decimal('area', 2, 0, 99999999.99, true, 'Diện tích phải là số dương (tối đa 2 chữ số thập phân).') // VAL-02.02, BR-10
            ->required('occupancy_status', 'Vui lòng chọn trạng thái sử dụng.')
            ->in('occupancy_status', array_keys(config('master_data.occupancy_status')), 'Trạng thái sử dụng không hợp lệ.');

        $buildingId = $this->isPositiveInt($data['building_id']) ? (int) $data['building_id'] : 0;
        $zoneId = $this->isPositiveInt($data['zone_id']) ? (int) $data['zone_id'] : null;
        $buildingChanged = $current === null || (int) $current['building_id'] !== $buildingId;
        $zoneChanged = $current === null || (int) ($current['zone_id'] ?? 0) !== (int) $zoneId;

        if (!$v->hasError('building_id')) {
            $building = $buildingId > 0 ? $this->buildings->find($buildingId) : null;
            if ($building === null) {
                $v->addError('building_id', 'Tòa nhà không tồn tại.');
            } elseif ($buildingChanged && $building['status'] !== 'hoat_dong') {
                $v->addError('building_id', 'Tòa nhà đã ngừng hoạt động, không thể gắn căn hộ vào tòa này.'); // D5
            } elseif ($current !== null && $buildingChanged && $this->apartments->countMeters((int) $current['id']) > 0) {
                $v->addError('building_id', 'Không thể đổi tòa nhà vì căn hộ đã có công tơ.'); // D7
            }
        }

        // D6: khu vực không bắt buộc (schema zone_id NULL); nếu chọn phải hợp lệ và thuộc đúng tòa.
        if ($data['zone_id'] !== '' && $zoneId === null) {
            $v->addError('zone_id', 'Khu vực không hợp lệ.');
        } elseif ($zoneId !== null && !$v->hasError('building_id')) {
            $zone = $this->zones->find($zoneId);
            if ($zone === null) {
                $v->addError('zone_id', 'Khu vực không tồn tại.');
            } elseif ((int) $zone['building_id'] !== $buildingId) {
                $v->addError('zone_id', 'Khu vực không thuộc tòa nhà đã chọn.');
            } elseif (($zoneChanged || $buildingChanged) && $zone['status'] !== 'hoat_dong') {
                $v->addError('zone_id', 'Khu vực đã ngừng hoạt động, không thể gắn căn hộ vào khu này.'); // D5
            }
        }

        if (!$v->hasError('building_id') && !$v->hasError('code')
            && $this->apartments->codeExists($buildingId, $data['code'], $current !== null ? (int) $current['id'] : null)) {
            $v->addError('code', 'Mã căn hộ đã tồn tại.'); // VAL-02.03
        }

        $v->throwIfFails();

        return [
            'building_id' => $buildingId,
            'zone_id' => $zoneId,
            'code' => $data['code'],
            'area' => number_format((float) $data['area'], 2, '.', ''),
            'occupancy_status' => $data['occupancy_status'],
        ];
    }
}
