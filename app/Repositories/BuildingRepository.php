<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-02.01 - bảng buildings. */
class BuildingRepository extends BaseRepository
{
    protected string $table = 'buildings';

    /** @param array{q:string,status:string} $filters */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(b.code LIKE :q1 OR b.name LIKE :q2)';
            $params['q1'] = $this->likeParam($filters['q']);
            $params['q2'] = $this->likeParam($filters['q']);
        }
        if ($filters['status'] !== '') {
            $where[] = 'b.status = :status';
            $params['status'] = $filters['status'];
        }

        $fromWhere = 'FROM buildings b' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $select = 'b.*,
            (SELECT COUNT(*) FROM zones z WHERE z.building_id = b.id) AS zone_count,
            (SELECT COUNT(*) FROM apartments a WHERE a.building_id = b.id) AS apartment_count';

        return $this->paginateQuery($select, $fromWhere, $params, 'b.code ASC, b.id ASC', $page, $perPage);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM buildings WHERE code = :code AND id <> :except_id',
            ['code' => $code, 'except_id' => $exceptId ?? 0]
        ) > 0;
    }

    public function countActiveZones(int $buildingId): int
    {
        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM zones WHERE building_id = :id AND status = 'hoat_dong'",
            ['id' => $buildingId]
        );
    }

    public function countActiveApartments(int $buildingId): int
    {
        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM apartments WHERE building_id = :id AND status = 'hoat_dong'",
            ['id' => $buildingId]
        );
    }

    /** Danh sách cho dropdown/bộ lọc. $activeOnly = chỉ tòa đang hoạt động. */
    public function options(bool $activeOnly): array
    {
        $sql = 'SELECT id, code, name, status FROM buildings'
            . ($activeOnly ? " WHERE status = 'hoat_dong'" : '')
            . ' ORDER BY code';

        return $this->fetchAllQuery($sql);
    }

    public function zonesOf(int $buildingId): array
    {
        return $this->fetchAllQuery(
            'SELECT z.*, (SELECT COUNT(*) FROM apartments a WHERE a.zone_id = z.id) AS apartment_count
             FROM zones z WHERE z.building_id = :id ORDER BY z.code',
            ['id' => $buildingId]
        );
    }

    /** @return array{total:int,active:int} */
    public function apartmentStats(int $buildingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'hoat_dong'), 0) AS active FROM apartments WHERE building_id = :id"
        );
        $stmt->execute(['id' => $buildingId]);
        $row = $stmt->fetch();

        return ['total' => (int) $row['total'], 'active' => (int) $row['active']];
    }
}
