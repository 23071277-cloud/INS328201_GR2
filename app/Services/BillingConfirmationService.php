<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\BillingPeriodRepository;

/**
 * FN-05.06 Kế toán kiểm tra & xác nhận dữ liệu tính phí - UC-03, G6, BR-22, BR-24.
 *   Đã tính --Kế toán xác nhận--> Đã xác nhận --(BQL duyệt, TUỲ CHỌN - J-18)--> Đã duyệt
 *   Đã tính --Kế toán trả lại (lý do bắt buộc)--> Bị trả lại --(sửa dữ liệu, tính lại)--> Đã tính
 * Không có thao tác sửa tay kết quả tính. Trường duy nhất Kế toán được nhập: nợ cũ (Q14, A-15/J-09).
 * Mọi thao tác: transaction, khoá kỳ rồi khoá bản tính (FOR UPDATE), version token, kiểm tra tính nhất quán
 * (bản CẦN TÍNH LẠI không được xác nhận/duyệt), audit.
 */
class BillingConfirmationService extends BaseService
{
    private ApartmentChargeRepository $charges;
    private BillingPeriodRepository $periods;
    private BillingCalculationService $calculation;

    public function __construct()
    {
        $this->charges = new ApartmentChargeRepository();
        $this->periods = new BillingPeriodRepository();
        $this->calculation = new BillingCalculationService();
    }

    /**
     * Kế toán xác nhận (từng bản / theo lô).
     * @param array<int|string,string> $items charge_id => version token lúc xem
     * @return array{done:int[], skipped:array<int,string>}
     */
    public function confirm(int $periodId, array $items): array
    {
        return $this->bulk($periodId, $items, 'da_tinh', 'da_xac_nhan', 'CONFIRM_APARTMENT_CHARGE',
            ['confirmed_by' => $this->actorId(), 'confirmed_at' => date('Y-m-d H:i:s')], true);
    }

    /** BQL duyệt sau khi Kế toán xác nhận - bước TUỲ CHỌN [ASSUMPTION A-13, NEED BA CONFIRMATION J-18]. */
    public function approve(int $periodId, array $items): array
    {
        return $this->bulk($periodId, $items, 'da_xac_nhan', 'da_duyet', 'APPROVE_APARTMENT_CHARGE',
            ['approved_by' => $this->actorId(), 'approved_at' => date('Y-m-d H:i:s')]);
    }

    /** Kế toán trả lại kèm lý do (UC-03 Alt, BR-24). */
    public function returnCharge(int $chargeId, string $reason, string $token): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessage('Vui lòng nhập lý do trả lại.', 'rejection_reason');
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessage('Lý do tối đa 500 ký tự.', 'rejection_reason');
        }

        $this->transaction($this->charges, function () use ($chargeId, $reason, $token): void {
            [$charge] = $this->lockCharge($chargeId, $token);
            if ($charge['status'] !== 'da_tinh') {
                throw ValidationException::withMessage('Chỉ trả lại được bản tính ở trạng thái Đã tính (hiện: ' . self::label($charge['status']) . ').');
            }
            $this->charges->updateById($chargeId, ['status' => 'bi_tra_lai', 'rejection_reason' => $reason]);
            $this->audit('RETURN_APARTMENT_CHARGE', 'apartment_charge', $chargeId,
                ['status' => 'da_tinh'], ['status' => 'bi_tra_lai', 'rejection_reason' => $reason]);
        });
    }

    /**
     * Kế toán nhập/điều chỉnh nợ cũ (FN-05.04 [SL]; [ASSUMPTION A-15] - nguồn nợ cũ NEED BA CONFIRMATION J-09).
     * total_due = total_arising + nợ cũ. Bản đã xác nhận/duyệt -> về Đã tính để xác nhận lại.
     */
    public function updateOldDebt(int $chargeId, string $amount, string $reason, string $token): void
    {
        $errors = [];
        $cents = Decimal::toCents($amount);
        if (Decimal::isNegative($amount)) {
            $errors['old_debt_amount'][] = 'Nợ cũ không được âm.';
        } elseif ($cents === null) {
            $errors['old_debt_amount'][] = 'Nợ cũ phải là số, tối đa 2 chữ số thập phân.';
        }
        $reason = trim($reason);
        if ($reason === '') {
            $errors['reason'][] = 'Vui lòng nhập lý do điều chỉnh nợ cũ.';
        } elseif (mb_strlen($reason) > 500) {
            $errors['reason'][] = 'Lý do tối đa 500 ký tự.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->transaction($this->charges, function () use ($chargeId, $cents, $reason, $token): void {
            [$charge] = $this->lockCharge($chargeId, $token);
            if (!in_array($charge['status'], config('billing.recalculable_statuses'), true)) {
                throw ValidationException::withMessage('Bản tính đã phát hành phiếu — không sửa được.');
            }
            $old = MoneyCalculator::fromDb($charge['old_debt_amount']);
            if ($old === $cents) {
                throw ValidationException::withMessage('Nợ cũ không thay đổi.', 'old_debt_amount');
            }
            $newStatus = in_array($charge['status'], ['da_xac_nhan', 'da_duyet'], true) ? 'da_tinh' : $charge['status'];
            $changes = [
                'old_debt_amount' => Decimal::fromCents($cents),
                'total_due' => Decimal::fromCents(MoneyCalculator::fromDb($charge['total_arising']) + $cents),
                'status' => $newStatus,
            ];
            if ($newStatus !== $charge['status']) {
                $changes += ['confirmed_by' => null, 'confirmed_at' => null, 'approved_by' => null, 'approved_at' => null];
            }
            $this->charges->updateById($chargeId, $changes);
            $this->audit('UPDATE_OLD_DEBT', 'apartment_charge', $chargeId,
                ['old_debt_amount' => $charge['old_debt_amount'], 'total_due' => $charge['total_due'], 'status' => $charge['status']],
                ['old_debt_amount' => $changes['old_debt_amount'], 'total_due' => $changes['total_due'], 'status' => $newStatus, 'reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------

    /** $j16Gate: [BA Proposal J-16] không xác nhận khi căn còn cảnh báo J-16 chưa được BQL kết luận A/B/C (tính nháp vẫn được). */
    private function bulk(int $periodId, array $items, string $from, string $to, string $action, array $stamp, bool $j16Gate = false): array
    {
        $j16 = $j16Gate ? new NoUsageConsumptionService() : null;
        if ($items === []) {
            throw ValidationException::withMessage('Vui lòng chọn ít nhất một bản tính.');
        }

        return $this->transaction($this->charges, function () use ($periodId, $items, $from, $to, $action, $stamp, $j16): array {
            $period = $this->periods->findForUpdate($periodId);
            if ($period === null) {
                throw ValidationException::withMessage('Kỳ không tồn tại.');
            }
            if ($period['status'] === 'da_khoa') {
                throw ValidationException::withMessage('Kỳ đã khóa — không thể thay đổi bản tính.');
            }

            $done = [];
            $skipped = [];
            ksort($items); // khoá theo thứ tự id cố định -> tránh deadlock
            foreach ($items as $id => $token) {
                $id = (int) $id;
                $charge = $this->charges->findForUpdate($id);
                if ($charge === null || (int) $charge['billing_period_id'] !== $periodId) {
                    $skipped[$id] = 'Không thuộc kỳ này.';
                    continue;
                }
                if ($charge['status'] !== $from) {
                    $skipped[$id] = $charge['status'] === $to ? 'Đã được xử lý trước đó.' : 'Trạng thái hiện tại là "' . self::label($charge['status']) . '".';
                    continue;
                }
                if ((string) $token !== BillingCalculationService::versionToken($charge)) {
                    $skipped[$id] = 'Bản tính đã thay đổi sau khi bạn mở màn hình — vui lòng tải lại.';
                    continue;
                }
                $consistency = $this->calculation->consistency($period, $charge);
                if ($consistency['state'] !== 'current') {
                    $skipped[$id] = 'CẦN TÍNH LẠI: ' . implode('; ', array_slice($consistency['reasons'], 0, 3));
                    continue;
                }
                if ($j16 !== null && ($blocking = $j16->blockingAlert((int) $charge['apartment_id'], $periodId)) !== null) {
                    $skipped[$id] = config('j16.gate_message') . ' (cảnh báo #' . $blocking['id'] . ')';
                    continue;
                }

                $this->charges->updateById($id, ['status' => $to] + $stamp);
                $this->audit($action, 'apartment_charge', $id, ['status' => $from],
                    ['status' => $to, 'total_due' => $charge['total_due']] + $stamp);
                $done[] = $id;
            }

            return ['done' => $done, 'skipped' => $skipped];
        });
    }

    /** Khoá kỳ (cùng thứ tự khoá với tính phí) rồi khoá bản tính; kiểm tra kỳ chưa khoá + token. */
    private function lockCharge(int $chargeId, string $token): array
    {
        $plain = $this->charges->find($chargeId);
        if ($plain === null) {
            throw ValidationException::withMessage('Bản tính không tồn tại.');
        }
        $period = $this->periods->findForUpdate((int) $plain['billing_period_id']);
        if ($period['status'] === 'da_khoa') {
            throw ValidationException::withMessage('Kỳ đã khóa — không thể thay đổi bản tính.');
        }
        $charge = $this->charges->findForUpdate($chargeId);
        if ($token !== BillingCalculationService::versionToken($charge)) {
            throw ValidationException::withMessage('Bản tính đã thay đổi sau khi bạn mở màn hình — vui lòng tải lại.');
        }

        return [$charge, $period];
    }

    private static function label(string $status): string
    {
        return config('billing.charge_status')[$status] ?? $status;
    }
}
