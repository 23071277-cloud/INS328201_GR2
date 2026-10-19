<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\BillingPeriodRepository;

/**
 * FN-05.07 Khoá kỳ / FN-05.08 Mở lại kỳ - G4, BR-22, BR-23, BR-04.
 *   Mở / Mở lại --khoá (đủ điều kiện)--> Đã khóa --BQL mở lại (lý do)--> Mở lại --khoá lại--> Đã khóa
 * Khoá: transaction + SELECT … FOR UPDATE trên kỳ (cùng khoá với nhập/sửa/duyệt/import chỉ số và tính phí),
 * kiểm tra điều kiện SAU khi giữ khoá -> không khoá được giữa chừng một thao tác khác.
 * Actor khoá: [ASSUMPTION J-10] Kế toán ([ĐX] FN-05.07). Không đòi "đã xuất phiếu" (FN-09.02 chưa làm).
 * Mở lại: chỉ BQL [TL 2.3]; KHÔNG xoá/sửa bản tính cũ (Q16).
 */
class PeriodClosingService extends BaseService
{
    private BillingPeriodRepository $periods;
    private ApartmentChargeRepository $charges;
    private BillingCalculationService $calculation;

    public function __construct()
    {
        $this->periods = new BillingPeriodRepository();
        $this->charges = new ApartmentChargeRepository();
        $this->calculation = new BillingCalculationService();
    }

    /** Tóm tắt tính phí cho trang kỳ: số bản tính theo trạng thái + bản bị trả lại (không kèm số tiền). */
    public function billingSummary(int $periodId): array
    {
        return ['counts' => $this->charges->statusCounts($periodId), 'returned' => $this->charges->returnedOfPeriod($periodId)];
    }

    /** Lý do chưa khoá được (rỗng = đủ điều kiện) - Q15. */
    public function lockBlockers(int $periodId): array
    {
        $period = $this->periods->find($periodId);
        if ($period === null) {
            return ['Kỳ không tồn tại.'];
        }
        if ($period['status'] === 'da_khoa') {
            return ['Kỳ đã khóa.'];
        }

        $reasons = [];
        $stats = $this->periods->stats($periodId);
        if ($stats['draft'] > 0) {
            $reasons[] = "Kỳ còn {$stats['draft']} chỉ số Nháp chưa xác nhận.";
        }
        if ($stats['pending'] > 0) {
            $reasons[] = "Còn {$stats['pending']} công tơ đang hoạt động chưa có chỉ số.";
        }
        $pendingImports = $this->periods->pendingImportCount($periodId);
        if ($pendingImports > 0) {
            $reasons[] = "Còn {$pendingImports} lô import chờ xác nhận/hủy.";
        }

        $lockable = config('billing.lockable_statuses');
        $adjustments = new BillingAdjustmentService();
        if (($pendingAdjustments = $adjustments->pendingForPeriod($periodId)) > 0) {
            $reasons[] = "Còn {$pendingAdjustments} đề nghị đối soát chờ quyết định.";
        }
        $byState = [];
        foreach ($this->calculation->overview($periodId) as $row) {
            $code = $row['apartment']['building_code'] . '/' . $row['apartment']['code'];
            if ($row['charge'] === null) {
                $byState['missing'][] = $code;
            } elseif (in_array($row['state'], ['stale', 'stale_error'], true)) {
                if ($row['state'] !== 'stale' || !$adjustments->reconciled((int)$row['charge']['id'])) {
                    $byState['stale'][] = $code;
                } elseif (!in_array($row['charge']['status'], $lockable, true)) {
                    $byState['unconfirmed'][] = $code;
                }
            } elseif (!in_array($row['charge']['status'], $lockable, true)) {
                $byState['unconfirmed'][] = $code . ' (' . (config('billing.charge_status')[$row['charge']['status']] ?? $row['charge']['status']) . ')';
            }
        }
        if (!empty($byState['missing'])) {
            $reasons[] = 'Căn chưa có bản tính phí: ' . self::list($byState['missing']) . '.';
        }
        if (!empty($byState['stale'])) {
            $reasons[] = 'Bản tính CẦN TÍNH LẠI (dữ liệu chỉ số/biểu giá/phí đã đổi sau khi tính): ' . self::list($byState['stale']) . '.';
        }
        if (!empty($byState['unconfirmed'])) {
            $reasons[] = 'Kỳ còn ' . count($byState['unconfirmed']) . ' bản tính chưa xác nhận: ' . self::list($byState['unconfirmed']) . '.';
        }

        return $reasons;
    }

    public function lock(int $periodId): void
    {
        $this->transaction($this->periods, function () use ($periodId): void {
            $period = $this->periods->findForUpdate($periodId);
            if ($period === null) {
                throw ValidationException::withMessage('Kỳ không tồn tại.');
            }
            if ($period['status'] === 'da_khoa') {
                throw ValidationException::withMessage("Kỳ {$period['period_code']} đã được khóa trước đó.");
            }
            $reasons = $this->lockBlockers($periodId);
            if ($reasons !== []) {
                throw new ValidationException(['lock' => $reasons]);
            }

            $now = date('Y-m-d H:i:s');
            $this->periods->updateById($periodId, ['status' => 'da_khoa', 'locked_by' => $this->actorId(), 'locked_at' => $now]);
            $this->audit('LOCK_BILLING_PERIOD', 'billing_period', $periodId,
                ['status' => $period['status']],
                ['status' => 'da_khoa', 'locked_by' => $this->actorId(), 'locked_at' => $now, 'charges' => $this->charges->statusCounts($periodId)]);
        });
    }

    /** @return string[] cảnh báo (không chặn) sau khi mở lại */
    /** @param bool $confirmPayments Payment module: bắt buộc xác nhận khi kỳ đã ghi nhận thanh toán (cảnh báo mạnh, không chặn cứng). */
    public function reopen(int $periodId, string $reason, bool $confirmPayments = false): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessage('Vui lòng nhập lý do mở lại kỳ.', 'reopen_reason');
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessage('Lý do tối đa 500 ký tự.', 'reopen_reason');
        }

        $paid = (new PaymentStatusService())->recordedForPeriod($periodId);
        if ($paid['charges'] > 0 && !$confirmPayments) {
            throw ValidationException::withMessage('Kỳ đã có ' . $paid['charges'] . ' căn ghi nhận thanh toán (' . money_vnd($paid['amount']) . '). Vui lòng tích xác nhận đã hiểu ảnh hưởng tới thanh toán trước khi mở lại kỳ.', 'confirm_payments');
        }

        return $this->transaction($this->periods, function () use ($periodId, $reason, $paid): array {
            $period = $this->periods->findForUpdate($periodId);
            if ($period === null) {
                throw ValidationException::withMessage('Kỳ không tồn tại.');
            }
            if ($period['status'] !== 'da_khoa') {
                throw ValidationException::withMessage("Kỳ {$period['period_code']} không ở trạng thái Đã khóa (có thể đã được mở lại).");
            }

            $now = date('Y-m-d H:i:s');
            $this->periods->updateById($periodId, ['status' => 'mo_lai', 'reopened_by' => $this->actorId(), 'reopened_at' => $now, 'reopen_reason' => $reason]);
            $this->audit('REOPEN_BILLING_PERIOD', 'billing_period', $periodId,
                ['status' => 'da_khoa', 'locked_by' => $period['locked_by'], 'locked_at' => $period['locked_at']],
                ['status' => 'mo_lai', 'reopened_by' => $this->actorId(), 'reopened_at' => $now, 'reason' => $reason]);

            $warnings = [];
            $later = $this->periods->laterPeriodsWithReadings($period['end_date']);
            if ($later !== []) {
                $warnings[] = 'Kỳ sau (' . implode(', ', $later) . ') đã có chỉ số: sửa CSC kỳ này sẽ không tự cập nhật CSĐ kỳ sau — cần tính lại các kỳ sau.';
            }
            if ($paid['charges'] > 0) {
                $warnings[] = 'CẢNH BÁO THANH TOÁN: ' . $paid['charges'] . ' căn đã ghi nhận thanh toán (' . money_vnd($paid['amount']) . '). Kỳ mở lại không nhận thanh toán mới; nếu tính lại làm đổi số phải thu, các căn này chuyển sang "Cần kiểm tra" trên dashboard thanh toán.';
            }
            if (($this->charges->statusCounts($periodId)['da_phat_hanh'] ?? 0) > 0) {
                $warnings[] = 'Kỳ có phiếu đã phát hành: chưa hỗ trợ phiếu thay thế — các bản này không tính lại được.';
            }

            return $warnings;
        });
    }

    private static function list(array $items): string
    {
        return implode(', ', array_slice($items, 0, 10)) . (count($items) > 10 ? ' … (+' . (count($items) - 10) . ')' : '');
    }
}
