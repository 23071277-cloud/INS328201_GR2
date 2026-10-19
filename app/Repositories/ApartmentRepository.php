<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-02.03 - bảng apartments. */
class ApartmentRepository extends BaseRepository
{
    protected string $table = 'apartments';

    /** @param array{q:string,building_id:string,zone_id:string,occupancy_status:string,status:string} $filters */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = 'a.code LIKE :q';
            $params['q'] = $this->likeParam($filters['q']);
        }
        foreach (['building_id', 'zone_id'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = "a.{$key} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }
        foreach (['occupancy_status', 'status'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = "a.{$key} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }

        $fromWhere = 'FROM apartments a
            JOIN buildings b ON b.id = a.building_id
            LEFT JOIN zones z ON z.id = a.zone_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $select = "a.*, b.code AS building_code, z.code AS zone_code, z.name AS zone_name,
            (SELECT COUNT(*) FROM meters m WHERE m.apartment_id = a.id AND m.status = 'hoat_dong') AS active_meter_count";

        return $this->paginateQuery($select, $fromWhere, $params, 'b.code ASC, a.code ASC, a.id ASC', $page, $perPage);
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, b.code AS building_code, b.name AS building_name, b.status AS building_status,
                    z.code AS zone_code, z.name AS zone_name, z.status AS zone_status
             FROM apartments a
             JOIN buildings b ON b.id = a.building_id
             LEFT JOIN zones z ON z.id = a.zone_id
             WHERE a.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** UNIQUE (building_id, code) của schema - VAL-02.03. */
    public function codeExists(int $buildingId, string $code, ?int $exceptId = null): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM apartments WHERE building_id = :building_id AND code = :code AND id <> :except_id',
            ['building_id' => $buildingId, 'code' => $code, 'except_id' => $exceptId ?? 0]
        ) > 0;
    }

    public function countMeters(int $apartmentId): int
    {
        return (int) $this->scalarQuery('SELECT COUNT(*) FROM meters WHERE apartment_id = :id', ['id' => $apartmentId]);
    }

    public function countActiveMeters(int $apartmentId): int
    {
        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM meters WHERE apartment_id = :id AND status = 'hoat_dong'",
            ['id' => $apartmentId]
        );
    }

    /** Mọi căn có mã này (mã chỉ duy nhất trong tòa - import FN-04.03 dùng để phát hiện mã trùng giữa các tòa). */
    public function findAllByCode(string $code): array
    {
        return $this->fetchAllQuery('SELECT * FROM apartments WHERE code = :c ORDER BY id', ['c' => $code]);
    }

    /** Căn hộ cho dropdown (kèm mã tòa). */
    public function options(bool $activeOnly): array
    {
        $sql = 'SELECT a.id, a.code, a.status, b.code AS building_code
                FROM apartments a JOIN buildings b ON b.id = a.building_id'
            . ($activeOnly ? " WHERE a.status = 'hoat_dong'" : '')
            . ' ORDER BY b.code, a.code';

        return $this->fetchAllQuery($sql);
    }
}
