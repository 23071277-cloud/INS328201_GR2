<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use App\Services\ZaloClient;
use PDOException;

/** Hộp thư đi Zalo ZNS (bảng zalo_notifications, migration 20261018). */
class ZaloNotificationRepository extends BaseRepository
{
    protected string $table = 'zalo_notifications';

    /** @var array<string,bool> */
    private static array $exists = [];

    /** Bảng có trên DB đang kết nối không (chưa chạy migration -> ẩn chức năng Zalo thay vì báo lỗi 500). */
    public function tableExists(): bool
    {
        $db = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        if (!array_key_exists($db, self::$exists)) {
            self::$exists[$db] = (int) $this->scalarQuery(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'zalo_notifications'"
            ) === 1;
        }

        return self::$exists[$db];
    }

    public static function forgetTableCache(): void
    {
        self::$exists = [];
    }

    /** Chèn bản ghi; trùng uq_zalo_dedupe (cùng bản tính + SĐT + loại + nội dung) -> null. */
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

    public function findByDedupe(int $chargeId, string $phone, string $type, string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM zalo_notifications
            WHERE apartment_charge_id = :c AND recipient_phone = :p AND notification_type = :t AND content_hash = :h');
        $stmt->execute(['c' => $chargeId, 'p' => $phone, 't' => $type, 'h' => $hash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Cư dân ĐANG HIỆU LỰC của căn có SĐT di động hợp lệ (cùng quy tắc người nhận email): is_active = 1, ngày $date trong hiệu lực.
     * Trả thêm khoá phone84 (đã chuẩn hoá). Có $residentId -> chỉ cư dân đó.
     */
    public function activeRecipients(int $apartmentId, string $date, ?int $residentId = null): array
    {
        $sql = "SELECT id, full_name, resident_role, phone FROM residents
                WHERE apartment_id = :a AND is_active = 1 AND phone IS NOT NULL AND phone <> ''
                  AND (effective_from IS NULL OR effective_from <= :d1) AND (effective_to IS NULL OR effective_to >= :d2)";
        $params = ['a' => $apartmentId, 'd1' => $date, 'd2' => $date];
        if ($residentId !== null) {
            $sql .= ' AND id = :r';
            $params['r'] = $residentId;
        }
        $out = [];
        foreach ($this->fetchAllQuery($sql . " ORDER BY FIELD(resident_role, 'chu_ho', 'khach_thue'), id", $params) as $row) {
            $phone84 = ZaloClient::normalizePhone((string) $row['phone']);
            if ($phone84 !== null) {
                $out[] = $row + ['phone84' => $phone84];
            }
        }

        return $out;
    }

    public function forCharge(int $chargeId, string $type = 'bill_notice'): array
    {
        return $this->fetchAllQuery('SELECT id, recipient_phone, recipient_name, status, attempt_count, sent_at, last_error, created_at
            FROM zalo_notifications WHERE apartment_charge_id = :c AND notification_type = :t ORDER BY id DESC', ['c' => $chargeId, 't' => $type]);
    }

    public function countFailed(): int
    {
        return (int) $this->scalarQuery("SELECT COUNT(*) FROM zalo_notifications WHERE status = 'failed'");
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT n.*, a.code AS apartment_code, b.code AS building_code, p.period_code, u.full_name AS created_by_name
            FROM zalo_notifications n
            JOIN apartments a ON a.id = n.apartment_id JOIN buildings b ON b.id = a.building_id
            LEFT JOIN billing_periods p ON p.id = n.billing_period_id LEFT JOIN users u ON u.id = n.created_by
            WHERE n.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Lọc: trạng thái, khoảng ngày tạo, người nhận (tên / SĐT), căn hộ (mã), kỳ. Mới nhất trước. */
    public function search(array $f, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($f['status'] ?? '') !== '') {
            $where[] = 'n.status = :status';
            $params['status'] = $f['status'];
        }
        if (($f['date_from'] ?? '') !== '') {
            $where[] = 'n.created_at >= :df';
            $params['df'] = $f['date_from'] . ' 00:00:00';
        }
        if (($f['date_to'] ?? '') !== '') {
            $where[] = 'n.created_at < DATE_ADD(:dt, INTERVAL 1 DAY)';
            $params['dt'] = $f['date_to'];
        }
        if (($f['recipient'] ?? '') !== '') {
            $where[] = '(n.recipient_phone LIKE :r1 OR n.recipient_name LIKE :r2)';
            $params['r1'] = $params['r2'] = $this->likeParam($f['recipient']);
        }
        if (($f['apartment'] ?? '') !== '') {
            $where[] = 'a.code LIKE :apt';
            $params['apt'] = $this->likeParam($f['apartment']);
        }
        if (($f['period_id'] ?? '') !== '') {
            $where[] = 'n.billing_period_id = :p';
            $params['p'] = (int) $f['period_id'];
        }

        return $this->paginateQuery(
            'n.id, n.recipient_phone, n.recipient_name, n.status, n.attempt_count, n.sent_at, n.last_error, n.transport, n.created_at,
             n.apartment_charge_id, a.code AS apartment_code, b.code AS building_code, p.period_code',
            'FROM zalo_notifications n JOIN apartments a ON a.id = n.apartment_id JOIN buildings b ON b.id = a.building_id
             LEFT JOIN billing_periods p ON p.id = n.billing_period_id WHERE ' . implode(' AND ', $where),
            $params,
            'n.id DESC',
            $page,
            $perPage
        );
    }
}
