<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Decimal;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\PaymentTransactionRepository;

/**
 * Payment module (chỉ đọc): mã thanh toán, trạng thái thanh toán TÍNH TỪ giao dịch, tổng hợp cho dashboard.
 * Trạng thái (không lưu - 3NF, không ghi vào apartment_charges):
 *   PAID            đã ghi nhận đúng số phải thu (matched / manual_matched) và không còn giao dịch cần kiểm tra
 *   PAYMENT_REVIEW  còn giao dịch cần kiểm tra gắn bản tính (sai số tiền / kỳ chưa khoá / trả trùng) hoặc số đã ghi nhận ≠ số phải thu
 *                   (vd kỳ mở lại và tính lại làm đổi số phải thu)
 *   UNPAID          còn lại
 * Không hỗ trợ thanh toán một phần: chỉ khớp đúng số tiền (PaymentReconciliationService).
 */
class PaymentStatusService extends BaseService
{
    public const UNPAID = 'UNPAID';
    public const PAID = 'PAID';
    public const REVIEW = 'PAYMENT_REVIEW';

    public const LABELS = [self::UNPAID => 'Chưa thanh toán', self::PAID => 'Đã thanh toán', self::REVIEW => 'Cần kiểm tra'];
    public const MATCH_LABELS = [
        'matched' => 'Khớp tự động', 'manual_matched' => 'Khớp thủ công', 'amount_mismatch' => 'Sai số tiền', 'unknown_ref' => 'Không rõ mã thanh toán',
        'not_payable' => 'Bản tính chưa thanh toán được', 'already_paid' => 'Bản tính đã thanh toán (trả trùng)', 'ignored' => 'Đã xử lý ngoài hệ thống',
    ];

    private PaymentTransactionRepository $payments;

    public function __construct()
    {
        $this->payments = new PaymentTransactionRepository();
    }

    /** Chức năng thanh toán có trên DB đang dùng không (bảng payment_transactions đã migrate). */
    public function available(): bool
    {
        return $this->payments->tableExists();
    }

    public static function ref(int $chargeId): string
    {
        return (string) config('payment.ref_prefix', 'EDSS') . str_pad((string) $chargeId, (int) config('payment.ref_digits', 6), '0', STR_PAD_LEFT);
    }

    /** Tìm mã thanh toán trong nội dung chuyển khoản (không phân biệt hoa thường, bỏ qua khoảng trắng / gạch giữa tiền tố và số). */
    public static function parseRef(string $content): ?int
    {
        $prefix = preg_quote((string) config('payment.ref_prefix', 'EDSS'), '/');
        if (!preg_match('/(?<![A-Z0-9])' . $prefix . '[\s\-_.]*0*(\d{1,12})(?!\d)/i', $content, $m)) {
            return null;
        }
        $id = (int) $m[1];

        return $id > 0 ? $id : null;
    }

    /** Thanh toán được: kỳ ĐÃ KHOÁ (số phải thu đã chốt) và bản tính đã xác nhận trở lên. */
    public static function payable(string $periodStatus, string $chargeStatus): bool
    {
        return $periodStatus === 'da_khoa' && in_array($chargeStatus, config('billing.lockable_statuses'), true);
    }

    /**
     * @param array{total_due:string} $charge
     * @param array{recorded:string, open_review:int, last_paid_at:?string}|null $agg
     * @return array{status:string, label:string, due:int, recorded:int, remaining:int, open_review:int, last_paid_at:?string}
     */
    public static function evaluate(array $charge, ?array $agg): array
    {
        $due = MoneyCalculator::fromDb($charge['total_due']);
        $recorded = MoneyCalculator::fromDb($agg['recorded'] ?? '0');
        $open = (int) ($agg['open_review'] ?? 0);
        if ($open > 0 || ($recorded > 0 && $recorded !== $due)) {
            $status = self::REVIEW;
        } elseif ($recorded === $due && $due > 0) {
            $status = self::PAID;
        } else {
            $status = self::UNPAID;
        }

        return ['status' => $status, 'label' => self::LABELS[$status], 'due' => $due, 'recorded' => $recorded, 'remaining' => max(0, $due - $recorded),
            'open_review' => $open, 'last_paid_at' => $agg['last_paid_at'] ?? null];
    }

    /** Trạng thái của 1 bản tính; null nếu chức năng chưa có trên DB. */
    public function forCharge(array $charge): ?array
    {
        if (!$this->available()) {
            return null;
        }

        return self::evaluate($charge, $this->payments->aggregateByCharge([(int) $charge['id']])[(int) $charge['id']] ?? null) + ['ref' => self::ref((int) $charge['id'])];
    }

    /** Dashboard thanh toán của kỳ: tổng phải thu / đã thu / chưa thu / cần kiểm tra + từng căn. */
    public function periodSummary(int $periodId): array
    {
        $period = (new BillingPeriodRepository())->find($periodId);
        $charges = $this->payments->chargesOfPeriod($periodId, config('billing.lockable_statuses'));
        $agg = $this->payments->aggregateByCharge(array_column($charges, 'id'));
        $tot = ['due' => 0, 'collected' => 0, 'outstanding' => 0, 'review_amount' => 0];
        $count = [self::UNPAID => 0, self::PAID => 0, self::REVIEW => 0];
        $rows = [];
        foreach ($charges as $c) {
            $st = self::evaluate($c, $agg[(int) $c['id']] ?? null);
            $tot['due'] += $st['due'];
            $tot['collected'] += $st['recorded'];
            if ($st['status'] === self::UNPAID) {
                $tot['outstanding'] += $st['due'];
            } elseif ($st['status'] === self::REVIEW) {
                $tot['review_amount'] += $st['due'];
            }
            $count[$st['status']]++;
            // 'status' của dòng = trạng thái THANH TOÁN; trạng thái quy trình bản tính giữ ở charge_status
            $rows[] = array_merge($c, $st, ['charge_status' => $c['status'], 'ref' => self::ref((int) $c['id'])]);
        }

        return [
            'period' => $period,
            'payable' => $period !== null && $period['status'] === 'da_khoa',
            'totals' => array_map(static fn (int $c): string => Decimal::fromCents($c), $tot),
            'counts' => $count + ['charges' => count($charges), 'unmatched' => $this->payments->countOpenReview()],
            'rows' => $rows,
        ];
    }

    public function periods(): array
    {
        return $this->payments->periodsWithCharges();
    }

    public function recent(int $limit = 20): array
    {
        return $this->payments->recent($limit);
    }

    public function reviewQueue(): array
    {
        return $this->payments->reviewQueue();
    }

    public function countOpenReview(): int
    {
        return $this->available() ? $this->payments->countOpenReview() : 0;
    }

    /** @return array{charges:int, amount:string} giao dịch đã ghi nhận vào kỳ (cảnh báo mở lại kỳ); 0 nếu chưa có chức năng */
    public function recordedForPeriod(int $periodId): array
    {
        return $this->available() ? $this->payments->recordedForPeriod($periodId) : ['charges' => 0, 'amount' => '0'];
    }
}
