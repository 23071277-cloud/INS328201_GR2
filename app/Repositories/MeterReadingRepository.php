<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use PDOException;

/** FN-04 - bảng meter_readings (UNIQUE meter_id + billing_period_id = BR-08). */
class MeterReadingRepository extends BaseRepository
{
    protected string $table = 'meter_readings';

    private const DETAIL_SELECT = 'SELECT r.*, p.period_code, p.status AS period_status, p.start_date, p.end_date,
            m.meter_code, m.utility_type, m.usage_type, m.display_label, m.status AS meter_status,
            a.id AS apartment_id, a.code AS apartment_code, a.status AS apartment_status,
            b.code AS building_code, z.code AS zone_code,
            ue.full_name AS entered_by_name, uc.full_name AS confirmed_by_name, ib.file_name AS import_file_name
        FROM meter_readings r
        JOIN billing_periods p ON p.id = r.billing_period_id
        JOIN meters m ON m.id = r.meter_id
        JOIN apartments a ON a.id = m.apartment_id
        JOIN buildings b ON b.id = a.building_id
        LEFT JOIN zones z ON z.id = a.zone_id
        LEFT JOIN users ue ON ue.id = r.entered_by
        LEFT JOIN users uc ON uc.id = r.confirmed_by
        LEFT JOIN import_batches ib ON ib.id = r.import_batch_id';

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare(self::DETAIL_SELECT . ' WHERE r.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** FN-04.09 @param array{period_id:string,zone_id:string,q:string,meter_code:string,utility_type:string,status:string,source:string} $f */
    public function search(array $f, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        foreach (['period_id' => 'r.billing_period_id', 'zone_id' => 'a.zone_id'] as $key => $column) {
            if ($f[$key] !== '') {
                $where[] = "{$column} = :{$key}";
                $params[$key] = (int) $f[$key];
            }
        }
        foreach (['utility_type' => 'm.utility_type', 'status' => 'r.status', 'source' => 'r.source'] as $key => $column) {
            if ($f[$key] !== '') {
                $where[] = "{$column} = :{$key}";
                $params[$key] = $f[$key];
            }
        }
        if ($f['q'] !== '') {
            $where[] = 'a.code LIKE :q';
            $params['q'] = $this->likeParam($f['q']);
        }
        if ($f['meter_code'] !== '') {
            $where[] = 'm.meter_code LIKE :meter_code';
            $params['meter_code'] = $this->likeParam($f['meter_code']);
        }

        $from = substr(self::DETAIL_SELECT, strpos(self::DETAIL_SELECT, 'FROM meter_readings'));
        $select = substr(self::DETAIL_SELECT, 7, strpos(self::DETAIL_SELECT, 'FROM meter_readings') - 7);

        return $this->paginateQuery($select, $from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params,
            'p.start_date DESC, b.code, a.code, m.utility_type, m.meter_code', $page, $perPage);
    }

    public function existsFor(int $meterId, int $periodId, ?int $exceptId = null): bool
    {
        return (int) $this->scalarQuery(
            'SELECT COUNT(*) FROM meter_readings WHERE meter_id = :m AND billing_period_id = :p AND id <> :x',
            ['m' => $meterId, 'p' => $periodId, 'x' => $exceptId ?? 0]
        ) > 0;
    }

    /** Chỉ số của 1 công tơ trong 1 kỳ (kèm thông tin kỳ/công tơ) - FN-07.09. */
    public function findByMeterPeriod(int $meterId, int $periodId): ?array
    {
        $stmt = $this->db->prepare(self::DETAIL_SELECT . ' WHERE r.meter_id = :m AND r.billing_period_id = :p');
        $stmt->execute(['m' => $meterId, 'p' => $periodId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Chỉ số gần nhất của công tơ ở các kỳ KẾT THÚC TRƯỚC ngày $startDate (nguồn CSĐ). */
    public function latestBefore(int $meterId, string $startDate): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.current_reading, r.status, p.period_code
             FROM meter_readings r JOIN billing_periods p ON p.id = r.billing_period_id
             WHERE r.meter_id = :m AND p.end_date < :s
             ORDER BY p.end_date DESC LIMIT 1'
        );
        $stmt->execute(['m' => $meterId, 's' => $startDate]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Bản nháp của kỳ cho màn hình duyệt (FN-04.08). $zeroOnly: lọc CL = 0 ([ĐX] cờ). */
    public function draftsOfPeriod(int $periodId, bool $zeroOnly = false): array
    {
        $stmt = $this->db->prepare(self::DETAIL_SELECT . " WHERE r.billing_period_id = :p AND r.status = 'nhap'"
            . ($zeroOnly ? ' AND r.consumption = 0' : '') . ' ORDER BY b.code, a.code, m.utility_type, m.meter_code');
        $stmt->execute(['p' => $periodId]);

        return $stmt->fetchAll();
    }

    /**
     * Worklist màn Nhập chỉ số (UX): công tơ đang hoạt động (căn đang hoạt động) + công tơ đã có chỉ số trong kỳ,
     * kèm chỉ số của kỳ (nếu có). Cùng thứ tự với danh sách chờ ghi số.
     */
    public function worklistOfPeriod(int $periodId): array
    {
        return $this->fetchAllQuery(
            "SELECT m.id, m.meter_code, m.utility_type, m.usage_type, m.display_label, m.initial_reading, m.status AS meter_status,
                    a.id AS apartment_id, a.code AS apartment_code, b.code AS building_code,
                    r.id AS reading_id, r.status AS reading_status, r.previous_reading, r.current_reading, r.consumption
             FROM meters m
             JOIN apartments a ON a.id = m.apartment_id
             JOIN buildings b ON b.id = a.building_id
             LEFT JOIN meter_readings r ON r.meter_id = m.id AND r.billing_period_id = :p
             WHERE (m.status = 'hoat_dong' AND a.status = 'hoat_dong') OR r.id IS NOT NULL
             ORDER BY b.code, a.code, m.utility_type, m.meter_code",
            ['p' => $periodId]
        );
    }

    /** Thêm chỉ số; trả null nếu vi phạm UNIQUE (meter, kỳ) do request đồng thời. */
    public function insertUnlessDuplicate(array $data): ?int
    {
        try {
            return $this->insertRow($data);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    public function idsOfBatch(int $batchId): array
    {
        return array_map('intval', array_column($this->fetchAllQuery('SELECT id FROM meter_readings WHERE import_batch_id = :b ORDER BY id', ['b' => $batchId]), 'id'));
    }
}
