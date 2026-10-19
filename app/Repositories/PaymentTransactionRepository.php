<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use PDOException;

/**
 * Payment module - bảng payment_transactions (giao dịch ngân hàng giả lập + kết quả đối soát).
 * Trạng thái thanh toán của bản tính KHÔNG lưu: tính từ tổng giao dịch đã ghi nhận (matched / manual_matched) và giao dịch còn
 * cần kiểm tra (PaymentStatusService). Repository KHÔNG ghi vào apartment_charges.
 */
class PaymentTransactionRepository extends BaseRepository
{
    protected string $table = 'payment_transactions';

    /** Kết quả đối soát đã ghi nhận tiền vào bản tính. */
    public const RECORDED = ['matched', 'manual_matched'];
    /** Kết quả cần Kế toán kiểm tra (chưa xử lý). */
    public const OPEN_REVIEW = ['amount_mismatch', 'unknown_ref', 'not_payable', 'already_paid'];

    private static array $exists = [];

    /** Bảng có trên DB đang kết nối không (DB thật chưa migrate / DB rollback v1 -> ẩn chức năng). Cache theo tên DB. */
    public function tableExists(): bool
    {
        $db = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        if (!array_key_exists($db, self::$exists)) {
            self::$exists[$db] = (int) $this->scalarQuery(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'payment_transactions'"
            ) === 1;
        }

        return self::$exists[$db];
    }

    public static function forgetTableCache(): void
    {
        self::$exists = [];
    }

    public function findByTransactionId(string $transactionId): ?array
    {
        return $this->fetchAllQuery('SELECT * FROM payment_transactions WHERE transaction_id = :t', ['t' => $transactionId])[0] ?? null;
    }

    /** INSERT; trả null nếu trùng transaction_id (callback lặp đồng thời - UNIQUE là chốt chặn cuối). */
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

    /**
     * Khoá theo thứ tự kỳ -> bản tính (quy ước chung của dự án): kỳ khoá chia sẻ (mở lại kỳ phải chờ), bản tính khoá ghi
     * (2 giao dịch cùng bản tính xử lý tuần tự). Chỉ ĐỌC bản tính - không ghi. @return array|null bản tính + trạng thái kỳ
     */
    public function lockChargeForPayment(int $chargeId): ?array
    {
        $periodId = $this->scalarQuery('SELECT billing_period_id FROM apartment_charges WHERE id = :id', ['id' => $chargeId]);
        if ($periodId === false || $periodId === null) {
            return null;
        }
        $period = $this->fetchAllQuery('SELECT id, period_code, status FROM billing_periods WHERE id = :p LOCK IN SHARE MODE', ['p' => (int) $periodId])[0] ?? null;
        $charge = $this->fetchAllQuery(
            'SELECT c.id, c.apartment_id, c.billing_period_id, c.status, c.total_due, a.code AS apartment_code, b.code AS building_code
             FROM apartment_charges c JOIN apartments a ON a.id = c.apartment_id JOIN buildings b ON b.id = a.building_id
             WHERE c.id = :id FOR UPDATE',
            ['id' => $chargeId]
        )[0] ?? null;
        if ($period === null || $charge === null) {
            return null;
        }

        return $charge + ['period_code' => $period['period_code'], 'period_status' => $period['status']];
    }

    /**
     * Số tiền đã ghi nhận vào bản tính - LOCKING READ (LOCK IN SHARE MODE): luôn thấy bản đã commit mới nhất. Bắt buộc dùng trong
     * transaction đối soát sau khi khoá bản tính: đọc thường ở REPEATABLE READ dùng snapshot từ lần đọc đầu -> giao dịch thứ 2 không thấy
     * giao dịch thứ 1 vừa commit và cũng "khớp" (ghi nhận đôi).
     */
    public function recordedForChargeLocked(int $chargeId): string
    {
        return (string) $this->scalarQuery(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_transactions WHERE apartment_charge_id = :c AND match_status IN ('matched','manual_matched') LOCK IN SHARE MODE",
            ['c' => $chargeId]
        );
    }

    /** Tổng tiền đã ghi nhận (matched + manual_matched) và số giao dịch còn cần kiểm tra, theo bản tính. @return array<int, array{recorded:string, open_review:int, last_paid_at:?string}> */
    public function aggregateByCharge(array $chargeIds): array
    {
        $chargeIds = array_values(array_unique(array_map('intval', $chargeIds)));
        if ($chargeIds === []) {
            return [];
        }
        $params = [];
        $in = [];
        foreach ($chargeIds as $i => $id) {
            $in[] = ":c{$i}";
            $params["c{$i}"] = $id;
        }
        $rows = $this->fetchAllQuery(
            "SELECT apartment_charge_id AS charge_id,
                    COALESCE(SUM(CASE WHEN match_status IN ('matched','manual_matched') THEN amount END), 0) AS recorded,
                    SUM(CASE WHEN match_status IN ('amount_mismatch','not_payable','already_paid') THEN 1 ELSE 0 END) AS open_review,
                    MAX(CASE WHEN match_status IN ('matched','manual_matched') THEN received_at END) AS last_paid_at
             FROM payment_transactions WHERE apartment_charge_id IN (" . implode(', ', $in) . ')
             GROUP BY apartment_charge_id',
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['charge_id']] = ['recorded' => (string) $r['recorded'], 'open_review' => (int) $r['open_review'], 'last_paid_at' => $r['last_paid_at']];
        }

        return $out;
    }

    /** Bản tính của kỳ dùng cho thanh toán (đã xác nhận trở lên) kèm căn hộ. */
    public function chargesOfPeriod(int $periodId, array $statuses): array
    {
        $params = ['p' => $periodId];
        $in = [];
        foreach (array_values($statuses) as $i => $s) {
            $in[] = ":s{$i}";
            $params["s{$i}"] = $s;
        }

        return $this->fetchAllQuery(
            'SELECT c.id, c.apartment_id, c.billing_period_id, c.status, c.total_due, a.code AS apartment_code, b.code AS building_code
             FROM apartment_charges c JOIN apartments a ON a.id = c.apartment_id JOIN buildings b ON b.id = a.building_id
             WHERE c.billing_period_id = :p AND c.status IN (' . implode(', ', $in) . ')
             ORDER BY b.code, a.code',
            $params
        );
    }

    /** Kỳ có bản tính (để chọn trên dashboard): kỳ đã khoá / mở lại. */
    public function periodsWithCharges(): array
    {
        return $this->fetchAllQuery(
            "SELECT p.id, p.period_code, p.status FROM billing_periods p
             WHERE p.status IN ('da_khoa','mo_lai') AND EXISTS (SELECT 1 FROM apartment_charges c WHERE c.billing_period_id = p.id)
             ORDER BY p.start_date DESC"
        );
    }

    private const LIST_SELECT = 'SELECT t.*, c.total_due, c.billing_period_id, a.code AS apartment_code, b.code AS building_code, p.period_code, u.full_name AS reviewed_by_name
        FROM payment_transactions t
        LEFT JOIN apartment_charges c ON c.id = t.apartment_charge_id
        LEFT JOIN apartments a ON a.id = c.apartment_id
        LEFT JOIN buildings b ON b.id = a.building_id
        LEFT JOIN billing_periods p ON p.id = c.billing_period_id
        LEFT JOIN users u ON u.id = t.reviewed_by';

    public function recent(int $limit): array
    {
        return $this->fetchAllQuery(self::LIST_SELECT . ' ORDER BY t.received_at DESC, t.id DESC LIMIT ' . max(1, min(100, $limit)));
    }

    /** Giao dịch còn cần kiểm tra (cũ nhất trước). */
    public function reviewQueue(): array
    {
        return $this->fetchAllQuery(self::LIST_SELECT . " WHERE t.match_status IN ('amount_mismatch','unknown_ref','not_payable','already_paid') ORDER BY t.received_at, t.id");
    }

    public function countOpenReview(): int
    {
        return (int) $this->scalarQuery("SELECT COUNT(*) FROM payment_transactions WHERE match_status IN ('amount_mismatch','unknown_ref','not_payable','already_paid')");
    }

    /** Giao dịch đã ghi nhận vào các bản tính của kỳ (cảnh báo khi mở lại kỳ). @return array{charges:int, amount:string} */
    public function recordedForPeriod(int $periodId): array
    {
        $row = $this->fetchAllQuery(
            "SELECT COUNT(DISTINCT t.apartment_charge_id) AS charges, COALESCE(SUM(t.amount), 0) AS amount
             FROM payment_transactions t JOIN apartment_charges c ON c.id = t.apartment_charge_id
             WHERE c.billing_period_id = :p AND t.match_status IN ('matched','manual_matched')",
            ['p' => $periodId]
        )[0] ?? ['charges' => 0, 'amount' => '0'];

        return ['charges' => (int) $row['charges'], 'amount' => (string) $row['amount']];
    }

    public function findDetailed(int $id): ?array
    {
        return $this->fetchAllQuery(self::LIST_SELECT . ' WHERE t.id = :id', ['id' => $id])[0] ?? null;
    }
}
