<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use PDOException;

/** STEP 25 - FN-10.03 hộp thư đi / lịch sử gửi email (bảng email_notifications, patch 004). */
class EmailNotificationRepository extends BaseRepository
{
    protected string $table = 'email_notifications';

    /** Chèn bản ghi; trùng uq_email_dedupe (cùng bản tính + người nhận + loại + nội dung) -> null. */
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

    public function findByDedupe(int $chargeId, string $email, string $type, string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM email_notifications
            WHERE apartment_charge_id = :c AND recipient_email = :e AND notification_type = :t AND content_hash = :h');
        $stmt->execute(['c' => $chargeId, 'e' => $email, 't' => $type, 'h' => $hash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Cư dân ĐANG HIỆU LỰC của căn có email (A-EMAIL-01): is_active = 1 và ngày $date nằm trong khoảng hiệu lực (nếu có).
     * Có $residentId -> chỉ cư dân đó (kiểm tra thuộc căn).
     */
    public function activeRecipients(int $apartmentId, string $date, ?int $residentId = null): array
    {
        $sql = "SELECT id, full_name, resident_role, email FROM residents
                WHERE apartment_id = :a AND is_active = 1 AND email IS NOT NULL AND email <> ''
                  AND (effective_from IS NULL OR effective_from <= :d1) AND (effective_to IS NULL OR effective_to >= :d2)";
        $params = ['a' => $apartmentId, 'd1' => $date, 'd2' => $date];
        if ($residentId !== null) {
            $sql .= ' AND id = :r';
            $params['r'] = $residentId;
        }

        return $this->fetchAllQuery($sql . " ORDER BY FIELD(resident_role, 'chu_ho', 'khach_thue'), id", $params);
    }

    /** Thư của bản tính theo LOẠI (mặc định phiếu phí) - đếm / gửi lại hàng loạt phiếu phí không lẫn email xác nhận thanh toán. */
    public function forCharge(int $chargeId, string $type = 'bill_notice'): array
    {
        return $this->fetchAllQuery('SELECT id, recipient_email, recipient_name, status, attempt_count, sent_at, last_error, created_at
            FROM email_notifications WHERE apartment_charge_id = :c AND notification_type = :t ORDER BY id DESC', ['c' => $chargeId, 't' => $type]);
    }

    /** Đã có thư loại $type cho bản tính chưa (bất kỳ trạng thái) - dùng cho "chỉ gửi 1 lần". */
    public function existsForChargeType(int $chargeId, string $type): bool
    {
        return (int) $this->scalarQuery('SELECT COUNT(*) FROM email_notifications WHERE apartment_charge_id = :c AND notification_type = :t', ['c' => $chargeId, 't' => $type]) > 0;
    }

    /** [J-16] bản ghi trùng uq_email_alert_dedupe (cùng cảnh báo + người nhận + loại + nội dung). */
    public function findByAlertDedupe(int $alertId, string $email, string $type, string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM email_notifications
            WHERE alert_id = :a AND recipient_email = :e AND notification_type = :t AND content_hash = :h');
        $stmt->execute(['a' => $alertId, 'e' => $email, 't' => $type, 'h' => $hash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** [J-16] lịch sử email xác minh của cảnh báo. */
    public function forAlert(int $alertId): array
    {
        return $this->fetchAllQuery('SELECT id, notification_type, recipient_email, recipient_name, status, attempt_count, sent_at, last_error, created_at
            FROM email_notifications WHERE alert_id = :a ORDER BY id DESC', ['a' => $alertId]);
    }

    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT n.*, a.code AS apartment_code, b.code AS building_code, p.period_code, u.full_name AS created_by_name
            FROM email_notifications n
            JOIN apartments a ON a.id = n.apartment_id JOIN buildings b ON b.id = a.building_id
            LEFT JOIN billing_periods p ON p.id = n.billing_period_id LEFT JOIN users u ON u.id = n.created_by
            WHERE n.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Lọc: trạng thái, khoảng ngày tạo, người nhận (một phần email / tên), căn hộ (mã), kỳ. Mới nhất trước. */
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
            $where[] = '(n.recipient_email LIKE :r1 OR n.recipient_name LIKE :r2)';
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
            'n.id, n.recipient_email, n.recipient_name, n.subject, n.status, n.attempt_count, n.sent_at, n.last_error, n.transport, n.created_at,
             n.apartment_charge_id, a.code AS apartment_code, b.code AS building_code, p.period_code',
            'FROM email_notifications n JOIN apartments a ON a.id = n.apartment_id JOIN buildings b ON b.id = a.building_id
             LEFT JOIN billing_periods p ON p.id = n.billing_period_id WHERE ' . implode(' AND ', $where),
            $params,
            'n.id DESC',
            $page,
            $perPage
        );
    }
}
