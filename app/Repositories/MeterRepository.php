<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-02.05 - bảng meters (công tơ điện/nước). */
class MeterRepository extends BaseRepository
{
    protected string $table = 'meters';

    /** @param array{q:string,building_id:string,apartment_id:string,utility_type:string,usage_type:string,status:string} $filters */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(m.meter_code LIKE :q1 OR a.code LIKE :q2)';
            $params['q1'] = $this->likeParam($filters['q']);
            $params['q2'] = $this->likeParam($filters['q']);
        }
        if ($filters['building_id'] !== '') {
            $where[] = 'a.building_id = :building_id';
            $params['building_id'] = (int) $filters['building_id'];
        }
        if ($filters['apartment_id'] !== '') {
            $where[] = 'm.apartment_id = :apartment_id';
            $params['apartment_id'] = (int) $filters['apartment_id'];
        }
        foreach (['utility_type', 'usage_type', 'status'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = "m.{$key} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }

        $fromWhere = 'FROM meters m
            JOIN apartments a ON a.id = m.apartment_id
            JOIN buildings b ON b.id = a.building_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $select = 'm.*, a.code AS apartment_code, b.code AS building_code,
            (SELECT COUNT(*) FROM meter_readings r WHERE r.meter_id = m.id) AS reading_count';

        return $this->paginateQuery($select, $fromWhere, $params, 'b.code ASC, a.code ASC, m.meter_code ASC', $page, $perPage);
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, a.code AS apartment_code, a.status AS apartment_status, a.building_id,
                    b.code AS building_code, b.name AS building_name
             FROM meters m
             JOIN apartments a ON a.id = m.apartment_id
             JOIN buildings b ON b.id = a.building_id
             WHERE m.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** UNIQUE meter_code của schema - VAL-02.05. */
    /** UX gợi ý mã (màn Thêm công tơ): danh sách mã đang có, chỉ để FE tránh gợi ý mã trùng - backend vẫn kiểm UNIQUE. */
    public function allCodes(): array
    {
        return array_column($this->fetchAllQuery('SELECT meter_code FROM meters ORDER BY meter_code'), 'meter_code');
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM meters WHERE meter_code = :code AND id <> :except_id',
            ['code' => $code, 'except_id' => $exceptId ?? 0]
        ) > 0;
    }

    /** Chỉ đọc meter_readings (FN-04) để biết công tơ đã có chỉ số hay chưa. */
    public function countReadings(int $meterId): int
    {
        return (int) $this->scalarQuery('SELECT COUNT(*) FROM meter_readings WHERE meter_id = :id', ['id' => $meterId]);
    }

    public function listByApartment(int $apartmentId): array
    {
        return $this->fetchAllQuery(
            'SELECT m.*, (SELECT COUNT(*) FROM meter_readings r WHERE r.meter_id = m.id) AS reading_count
             FROM meters m WHERE m.apartment_id = :id ORDER BY m.status, m.utility_type, m.meter_code',
            ['id' => $apartmentId]
        );
    }
}
