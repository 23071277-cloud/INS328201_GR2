<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** Đếm việc tồn đọng cho badge sidebar (chỉ đọc, mỗi khoá 1 truy vấn COUNT). */
class IssueCounterRepository extends BaseRepository
{
    protected string $table = 'alerts';

    private const OPEN_PERIODS = "('mo', 'mo_lai')";

    /**
     * BQL - cảnh báo cần BQL xử lý: Mới (cần review) + đã có kết quả Kỹ thuật (cần kết luận)
     * + J-16 chưa kết luận A/B/C và không còn chờ Kỹ thuật. Cảnh báo đã đóng không đếm.
     */
    public function alertsForManager(string $j16Type, array $resolving): int
    {
        $in = implode(',', array_map(fn (string $c): string => $this->db->quote($c), $resolving));

        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM alerts al WHERE al.status <> 'da_dong' AND (
                al.status = 'moi'
                OR EXISTS (SELECT 1 FROM inspection_cases c WHERE c.alert_id = al.id AND c.status = 'da_co_ket_qua')
                OR (al.alert_type = :t AND (al.conclusion IS NULL OR al.conclusion NOT IN ({$in}))
                    AND NOT EXISTS (SELECT 1 FROM inspection_cases c2 WHERE c2.alert_id = al.id AND c2.status = 'da_giao')))",
            ['t' => $j16Type]
        );
    }

    /** Kỹ thuật - case kiểm tra được giao cho mình đang chờ kết quả. */
    public function casesAssignedTo(int $userId): int
    {
        return (int) $this->scalarQuery("SELECT COUNT(*) FROM inspection_cases WHERE assigned_to = :u AND status = 'da_giao'", ['u' => $userId]);
    }

    /** BQL - chỉ số Nháp chờ duyệt ở kỳ chưa khoá. */
    public function draftReadings(): int
    {
        return (int) $this->scalarQuery("SELECT COUNT(*) FROM meter_readings r JOIN billing_periods p ON p.id = r.billing_period_id
            WHERE r.status = 'nhap' AND p.status IN " . self::OPEN_PERIODS);
    }

    /** Kỹ thuật - công tơ đang hoạt động (căn hoạt động) chưa có chỉ số ở các kỳ đang mở. */
    public function unreadMeters(): int
    {
        return (int) $this->scalarQuery("SELECT COUNT(*) FROM billing_periods p
            JOIN meters m ON m.status = 'hoat_dong' JOIN apartments a ON a.id = m.apartment_id AND a.status = 'hoat_dong'
            WHERE p.status IN " . self::OPEN_PERIODS . '
              AND NOT EXISTS (SELECT 1 FROM meter_readings r WHERE r.meter_id = m.id AND r.billing_period_id = p.id)');
    }

    /**
     * Kế toán - bản tính cần xác nhận (Đã tính) hoặc bị trả lại ở kỳ chưa khoá; bản đang bị J-16 chặn
     * (chưa thể xác nhận - chờ BQL) không đếm.
     */
    public function chargesToProcess(string $j16Type, array $resolving): int
    {
        $in = implode(',', array_map(fn (string $c): string => $this->db->quote($c), $resolving));

        return (int) $this->scalarQuery(
            "SELECT COUNT(*) FROM apartment_charges c JOIN billing_periods p ON p.id = c.billing_period_id
             WHERE p.status IN " . self::OPEN_PERIODS . " AND (c.status = 'bi_tra_lai' OR (c.status = 'da_tinh' AND NOT EXISTS (
                SELECT 1 FROM alerts al WHERE al.alert_type = :t AND al.apartment_id = c.apartment_id AND al.billing_period_id = c.billing_period_id
                  AND (al.conclusion IS NULL OR al.conclusion NOT IN ({$in})))))",
            ['t' => $j16Type]
        );
    }

    /** Email + tin Zalo gửi lỗi cần gửi lại (bảng Zalo chưa migrate -> chỉ đếm email, không làm hỏng badge khác). */
    public function failedEmails(): int
    {
        $n = (int) $this->scalarQuery("SELECT COUNT(*) FROM email_notifications WHERE status = 'failed'");
        if ((new ZaloNotificationRepository())->tableExists()) {
            $n += (new ZaloNotificationRepository())->countFailed();
        }

        return $n;
    }
}
