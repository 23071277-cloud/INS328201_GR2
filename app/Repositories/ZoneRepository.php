<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-02.02 - bảng zones (Building 1 -> N Zone). */
class ZoneRepository extends BaseRepository
{
    protected string $table = 'zones';

    /** @param array{q:string,building_id:string,zone_type:string,status:string} $filters */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(z.code LIKE :q1 OR z.name LIKE :q2)';
            $params['q1'] = $this->likeParam($filters['q']);
            $params['q2'] = $this->likeParam($filters['q']);
        }
        if ($filters['building_id'] !== '') {
            $where[] = 'z.building_id = :building_id';
            $params['building_id'] = (int) $filters['building_id'];
        }
        if ($filters['zone_type'] !== '') {
            $where[] = 'z.zone_type = :zone_type';
            $params['zone_type'] = $filters['zone_type'];
        }
        if ($filters['status'] !== '') {
            $where[] = 'z.status = :status';
            $params['status'] = $filters['status'];
        }

        $fromWhere = 'FROM zones z JOIN buildings b ON b.id = z.building_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $select = 'z.*, b.code AS building_code, b.name AS building_name,
            (SELECT COUNT(*) FROM apartments a WHERE a.zone_id = z.id) AS apartment_count';

        return $this->paginateQuery($select, $fromWhere, $params, 'b.code ASC, z.code ASC, z.id ASC', $page, $perPage);
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT z.*, b.code AS building_code, b.name AS building_name, b.status AS building_status
             FROM zones z JOIN buildings b ON b.id = z.building_id WHERE z.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** UNIQUE (building_id, code) của schema. */
    public function codeExists(int $buildingId, string $code, ?int $exceptId = null): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM zones WHERE building_id = :building_id AND code = :code AND id <> :except_id',
            ['building_id' => $buildingId, 'code' => $code, 'except_id' => $exceptId ?? 0]
        ) > 0;
    }

    public function countApartments(int $zoneId): int
    {
        return (int) $this->scalarQuery('SELECT COUNT(*) FROM apartments WHERE zone_id = :id', ['id' => $zoneId]);
    }

    public function countActiveApartments(int $zoneId): int
    {
        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM apartments WHERE zone_id = :id AND status = 'hoat_dong'",
            ['id' => $zoneId]
        );
    }

    /** Khu cho dropdown, kèm building_id để form lọc theo tòa (lọc thật vẫn ở Service). */
    public function options(bool $activeOnly): array
    {
        $sql = 'SELECT z.id, z.code, z.name, z.status, z.building_id, b.code AS building_code
                FROM zones z JOIN buildings b ON b.id = z.building_id'
            . ($activeOnly ? " WHERE z.status = 'hoat_dong'" : '')
            . ' ORDER BY b.code, z.code';

        return $this->fetchAllQuery($sql);
    }

    public function apartmentsOf(int $zoneId): array
    {
        return $this->fetchAllQuery(
            'SELECT id, code, area, occupancy_status, status FROM apartments WHERE zone_id = :id ORDER BY code',
            ['id' => $zoneId]
        );
    }
}
