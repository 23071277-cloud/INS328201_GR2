<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\BuildingRepository;
use App\Repositories\ZoneRepository;

/**
 * FN-02.02 Quản lý khu vực | [SL] 2.1 "định dạng mã theo khu"; Building 1 -> N Zone.
 * Phân quyền chưa có trong ma trận 2.3 -> [NEED BA CONFIRMATION J-02], tạm áp như căn hộ.
 * [ĐX] Mã khu duy nhất (trong tòa - UNIQUE building_id+code của schema);
 * [ĐX] không xoá/ngừng khu còn căn hộ đang hoạt động (D3).
 */
class ZoneService extends BaseService
{
    private ZoneRepository $zones;
    private BuildingRepository $buildings;

    public function __construct()
    {
        $this->zones = new ZoneRepository();
        $this->buildings = new BuildingRepository();
    }

    public function list(array $filters, int $page): array
    {
        $filters['status'] = array_key_exists($filters['status'], config('master_data.record_status')) ? $filters['status'] : '';
        $filters['zone_type'] = array_key_exists($filters['zone_type'], config('master_data.zone_type')) ? $filters['zone_type'] : '';
        $filters['building_id'] = $this->isPositiveInt($filters['building_id']) ? $filters['building_id'] : '';

        return $this->zones->search($filters, $page, (int) config('master_data.per_page', 20));
    }

    public function find(int $id): ?array
    {
        return $this->zones->findDetailed($id);
    }

    public function detail(int $id): ?array
    {
        $zone = $this->zones->findDetailed($id);
        if ($zone !== null) {
            $zone['apartments'] = $this->zones->apartmentsOf($id);
        }

        return $zone;
    }

    public function options(bool $activeOnly): array
    {
        return $this->zones->options($activeOnly);
    }

    public function create(array $input): int
    {
        return $this->transaction($this->zones, function () use ($input): int {
            $data = $this->validate($input, null);
            $id = $this->zones->insertRow($data + ['status' => 'hoat_dong']);
            $this->audit('create', 'zone', $id, null, $this->zones->find($id));

            return $id;
        });
    }

    public function update(int $id, array $input): bool
    {
        return $this->transaction($this->zones, function () use ($id, $input): bool {
            $current = $this->lockOrFail($id);
            $this->assertNotStale($current, $input['updated_at'] ?? null);

            $changes = $this->diff($current, $this->validate($input, $current));
            if ($changes === []) {
                return false;
            }

            $this->zones->updateById($id, $changes);
            $this->audit('update', 'zone', $id, array_intersect_key($current, $changes), $changes);

            return true;
        });
    }

    public function deactivate(int $id): void
    {
        $this->transaction($this->zones, function () use ($id): void {
            $current = $this->lockOrFail($id);
            if ($current['status'] === 'ngung') {
                throw ValidationException::withMessage('Khu vực đã ở trạng thái Ngừng.');
            }

            $activeApartments = $this->zones->countActiveApartments($id);
            if ($activeApartments > 0) {
                throw ValidationException::withMessage(
                    "Không thể ngừng khu vực: còn {$activeApartments} căn hộ đang hoạt động trong khu. Vui lòng ngừng hoặc chuyển các căn hộ trước."
                );
            }

            $this->zones->updateById($id, ['status' => 'ngung']);
            $this->audit('deactivate', 'zone', $id, ['status' => $current['status']], ['status' => 'ngung']);
        });
    }

    private function lockOrFail(int $id): array
    {
        $row = $this->zones->findForUpdate($id);
        if ($row === null) {
            throw ValidationException::withMessage('Khu vực không tồn tại.');
        }

        return $row;
    }

    /** @param array|null $current bản ghi đang sửa (null khi tạo mới) */
    private function validate(array $input, ?array $current): array
    {
        $data = [
            'building_id' => (string) ($input['building_id'] ?? ''),
            'code' => (string) ($input['code'] ?? ''),
            'name' => (string) ($input['name'] ?? ''),
            'zone_type' => (string) ($input['zone_type'] ?? ''),
        ];

        $v = new Validator($data);
        $v->required('building_id', 'Vui lòng chọn tòa nhà.')
            ->required('code', 'Vui lòng nhập mã khu vực.')
            ->maxLength('code', 30, 'Mã khu vực tối đa 30 ký tự.')
            ->pattern('code', self::CODE_REGEX, self::CODE_MESSAGE)
            ->required('name', 'Vui lòng nhập tên khu vực.')
            ->maxLength('name', 150, 'Tên khu vực tối đa 150 ký tự.')
            ->in('zone_type', array_keys(config('master_data.zone_type')), 'Loại khu không hợp lệ.');

        $buildingId = $this->isPositiveInt($data['building_id']) ? (int) $data['building_id'] : 0;
        $buildingChanged = $current === null || (int) $current['building_id'] !== $buildingId;

        if (!$v->hasError('building_id')) {
            $building = $buildingId > 0 ? $this->buildings->find($buildingId) : null;
            if ($building === null) {
                $v->addError('building_id', 'Tòa nhà không tồn tại.');
            } elseif ($buildingChanged && $building['status'] !== 'hoat_dong') {
                // D5: không tạo/chuyển khu vào tòa đã ngừng
                $v->addError('building_id', 'Tòa nhà đã ngừng hoạt động, không thể thêm khu vực vào tòa này.');
            } elseif ($current !== null && $buildingChanged && $this->zones->countApartments((int) $current['id']) > 0) {
                // D7: tránh lệch building_id giữa khu và căn hộ trong khu
                $v->addError('building_id', 'Không thể đổi tòa nhà vì khu vực đã có căn hộ.');
            }
        }

        if (!$v->hasError('building_id') && !$v->hasError('code')
            && $this->zones->codeExists($buildingId, $data['code'], $current !== null ? (int) $current['id'] : null)) {
            $v->addError('code', 'Mã khu vực đã tồn tại trong tòa nhà này.');
        }

        $v->throwIfFails();

        return [
            'building_id' => $buildingId,
            'code' => $data['code'],
            'name' => $data['name'],
            'zone_type' => $this->nullIfEmpty($data['zone_type']),
        ];
    }
}
