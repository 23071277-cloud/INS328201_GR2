<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\BuildingRepository;

/**
 * FN-02.01 Quản lý tòa nhà | [TL] FR-03, 2.3 (BQL CRUD + Deactivate; KT, KTo View).
 * Không xoá cứng [TECHNICAL ASSUMPTION D1]; ngừng khi còn khu/căn hộ đang hoạt
 * động bị chặn [TECHNICAL ASSUMPTION D3].
 */
class BuildingService extends BaseService
{
    private BuildingRepository $buildings;

    public function __construct()
    {
        $this->buildings = new BuildingRepository();
    }

    public function list(array $filters, int $page): array
    {
        $filters['status'] = array_key_exists($filters['status'], config('master_data.record_status')) ? $filters['status'] : '';

        return $this->buildings->search($filters, $page, (int) config('master_data.per_page', 20));
    }

    public function find(int $id): ?array
    {
        return $this->buildings->find($id);
    }

    public function detail(int $id): ?array
    {
        $building = $this->buildings->find($id);
        if ($building === null) {
            return null;
        }

        $building['zones'] = $this->buildings->zonesOf($id);
        $building['apartment_stats'] = $this->buildings->apartmentStats($id);

        return $building;
    }

    public function options(bool $activeOnly): array
    {
        return $this->buildings->options($activeOnly);
    }

    public function create(array $input): int
    {
        $data = $this->validate($input, null);

        return $this->transaction($this->buildings, function () use ($data): int {
            $row = $data + ['status' => 'hoat_dong', 'created_by' => $this->actorId()];
            $id = $this->buildings->insertRow($row);
            $this->audit('create', 'building', $id, null, $this->buildings->find($id));

            return $id;
        });
    }

    /** @return bool false nếu không có thay đổi */
    public function update(int $id, array $input): bool
    {
        return $this->transaction($this->buildings, function () use ($id, $input): bool {
            $current = $this->lockOrFail($id);
            $this->assertNotStale($current, $input['updated_at'] ?? null);

            $changes = $this->diff($current, $this->validate($input, $id));
            if ($changes === []) {
                return false;
            }

            $this->buildings->updateById($id, $changes);
            $this->audit('update', 'building', $id, array_intersect_key($current, $changes), $changes);

            return true;
        });
    }

    public function deactivate(int $id): void
    {
        $this->transaction($this->buildings, function () use ($id): void {
            $current = $this->lockOrFail($id);
            if ($current['status'] === 'ngung') {
                throw ValidationException::withMessage('Tòa nhà đã ở trạng thái Ngừng.');
            }

            $activeZones = $this->buildings->countActiveZones($id);
            $activeApartments = $this->buildings->countActiveApartments($id);
            if ($activeZones > 0 || $activeApartments > 0) {
                throw ValidationException::withMessage(sprintf(
                    'Không thể ngừng tòa nhà: còn %d khu vực và %d căn hộ đang hoạt động. Vui lòng ngừng các khu vực/căn hộ trước.',
                    $activeZones,
                    $activeApartments
                ));
            }

            $this->buildings->updateById($id, ['status' => 'ngung']);
            $this->audit('deactivate', 'building', $id, ['status' => $current['status']], ['status' => 'ngung']);
        });
    }

    private function lockOrFail(int $id): array
    {
        $row = $this->buildings->findForUpdate($id);
        if ($row === null) {
            throw ValidationException::withMessage('Tòa nhà không tồn tại.');
        }

        return $row;
    }

    /** @return array{code:string,name:string} */
    private function validate(array $input, ?int $exceptId): array
    {
        $data = [
            'code' => (string) ($input['code'] ?? ''),
            'name' => (string) ($input['name'] ?? ''),
        ];

        $v = new Validator($data);
        $v->required('code', 'Vui lòng nhập mã tòa nhà.')
            ->maxLength('code', 30, 'Mã tòa nhà tối đa 30 ký tự.')
            ->pattern('code', self::CODE_REGEX, self::CODE_MESSAGE)
            ->required('name', 'Vui lòng nhập tên tòa nhà.') // [ĐX] tên bắt buộc
            ->maxLength('name', 150, 'Tên tòa nhà tối đa 150 ký tự.');

        if (!$v->hasError('code') && $this->buildings->codeExists($data['code'], $exceptId)) {
            $v->addError('code', 'Mã tòa nhà đã tồn tại.');
        }

        $v->throwIfFails();

        return $data;
    }
}
