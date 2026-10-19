<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\PaymentTransactionRepository;
use Throwable;

/**
 * Payment module - nhận giao dịch ngân hàng (Mock Bank, callback ký HMAC) và đối soát TỰ ĐỘNG với bản tính.
 *  - Idempotency: transaction_id UNIQUE - callback lặp (kể cả đồng thời) trả "đã xử lý", không ghi gì thêm, không gửi email lại.
 *  - Khớp: mã thanh toán trong nội dung chuyển khoản -> bản tính; kỳ phải ĐÃ KHOÁ; số tiền phải ĐÚNG số còn phải thu (không thanh
 *    toán một phần). Sai số tiền / không rõ mã / kỳ chưa khoá / bản tính đã thanh toán -> lưu giao dịch, chờ Kế toán kiểm tra.
 *  - KHÔNG ghi vào apartment_charges (kể cả bản tính kỳ đã khoá); trạng thái thanh toán tính từ giao dịch (PaymentStatusService).
 *  - Email xác nhận thanh toán gửi SAU khi transaction commit, chỉ khi bản tính chuyển UNPAID -> PAID; lỗi gửi email không làm hỏng giao dịch.
 */
class PaymentReconciliationService extends BaseService
{
    private PaymentTransactionRepository $payments;

    public function __construct()
    {
        $this->payments = new PaymentTransactionRepository();
    }

    public static function mockBankEnabled(): bool
    {
        return (bool) config('payment.mock_bank.enabled', false) && strlen((string) config('payment.mock_bank.secret', '')) >= 16;
    }

    public static function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, (string) config('payment.mock_bank.secret', ''));
    }

    /**
     * Callback ngân hàng giả lập: xác thực HMAC-SHA256 trên body thô rồi xử lý. Ném PaymentCallbackException (kèm mã HTTP).
     * @return array{id:int, transaction_id:string, match_status:string, duplicate:bool, charge_id:?int, became_paid:bool, emails:array}
     */
    public function handleCallback(string $rawBody, string $signature): array
    {
        if (!self::mockBankEnabled()) {
            throw new PaymentCallbackException('Mock Bank đang tắt.', 404);
        }
        if (!(new PaymentStatusService())->available()) {
            throw new PaymentCallbackException('Chức năng thanh toán chưa được kích hoạt trên cơ sở dữ liệu này.', 503);
        }
        if ($signature === '' || !hash_equals(self::sign($rawBody), strtolower(trim($signature)))) {
            throw new PaymentCallbackException('Chữ ký HMAC không hợp lệ.', 401);
        }
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new PaymentCallbackException('Payload không phải JSON hợp lệ.', 400);
        }
        try {
            return $this->receive($payload, $rawBody);
        } catch (ValidationException $e) {
            throw new PaymentCallbackException(implode(' ', array_merge(...array_values($e->errors()))), 422);
        }
    }

    /**
     * Ghi nhận + đối soát 1 giao dịch (đã xác thực). @param array<string,mixed> $payload {transaction_id, amount, content, received_at?, payer_name?}
     * @return array{id:int, transaction_id:string, match_status:string, duplicate:bool, charge_id:?int, became_paid:bool, emails:array}
     */
    public function receive(array $payload, ?string $rawBody = null): array
    {
        $data = $this->validatePayload($payload);
        $raw = $rawBody ?? (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        $result = $this->transaction($this->payments, function () use ($data, $raw): array {
            $existing = $this->payments->findByTransactionId($data['transaction_id']);
            if ($existing !== null) {
                return $this->duplicate($existing);
            }
            $chargeId = PaymentStatusService::parseRef($data['content']);
            $charge = $chargeId !== null ? $this->payments->lockChargeForPayment($chargeId) : null;
            [$status, $note, $becamePaid] = $this->decide($charge, $data['amount_cents']);
            $id = $this->payments->insertUnlessDuplicate([
                'transaction_id' => $data['transaction_id'],
                'source' => 'mock_bank',
                'amount' => Decimal::fromCents($data['amount_cents']),
                'transfer_content' => $data['content'],
                'payer_name' => $data['payer_name'],
                'received_at' => $data['received_at'],
                'apartment_charge_id' => $charge !== null ? (int) $charge['id'] : null,
                'match_status' => $status,
                'match_note' => $note,
                'raw_payload' => $raw,
            ]);
            if ($id === null) { // callback trùng đến đồng thời: bản kia đã ghi
                return $this->duplicate((array) $this->payments->findByTransactionId($data['transaction_id']));
            }
            $this->audit('PAYMENT_RECEIVED', 'payment_transaction', $id, null, [
                'transaction_id' => $data['transaction_id'], 'amount' => Decimal::fromCents($data['amount_cents']), 'match_status' => $status,
                'apartment_charge_id' => $charge['id'] ?? null, 'payment_ref' => $chargeId !== null ? PaymentStatusService::ref($chargeId) : null, 'note' => $note,
            ]);

            return ['id' => $id, 'transaction_id' => $data['transaction_id'], 'match_status' => $status, 'duplicate' => false,
                'charge_id' => $charge !== null ? (int) $charge['id'] : null, 'became_paid' => $becamePaid, 'emails' => []];
        });

        return $this->afterCommit($result);
    }

    /** Kế toán khớp thủ công giao dịch cần kiểm tra vào bản tính (mã thanh toán) - vẫn phải đúng số tiền còn phải thu. */
    public function matchManually(int $id, string $ref, string $note): array
    {
        $note = $this->requireNote($note);
        $chargeId = PaymentStatusService::parseRef($ref);
        if ($chargeId === null) {
            throw ValidationException::withMessage('Mã thanh toán không hợp lệ (vd ' . PaymentStatusService::ref(12) . ').', 'ref');
        }
        $result = $this->transaction($this->payments, function () use ($id, $chargeId, $note): array {
            $t = $this->lockOpen($id);
            $charge = $this->payments->lockChargeForPayment($chargeId);
            if ($charge === null) {
                throw ValidationException::withMessage('Không tìm thấy bản tính có mã thanh toán này.', 'ref');
            }
            if (!PaymentStatusService::payable((string) $charge['period_status'], (string) $charge['status'])) {
                throw ValidationException::withMessage("Bản tính kỳ {$charge['period_code']} chưa thanh toán được (kỳ phải đã khoá).", 'ref');
            }
            $remaining = $this->remaining($charge);
            $amount = MoneyCalculator::fromDb($t['amount']);
            if ($remaining <= 0) {
                throw ValidationException::withMessage('Bản tính này đã thanh toán đủ — không khớp thêm giao dịch.', 'ref');
            }
            if ($amount !== $remaining) {
                throw ValidationException::withMessage('Số tiền giao dịch ' . money_vnd(Decimal::fromCents($amount)) . ' khác số còn phải thu ' . money_vnd(Decimal::fromCents($remaining))
                    . ' — không hỗ trợ thanh toán một phần; hãy "Bỏ qua" và xử lý hoàn tiền ngoài hệ thống.', 'ref');
            }
            $now = date('Y-m-d H:i:s');
            $this->payments->updateById($id, ['match_status' => 'manual_matched', 'apartment_charge_id' => (int) $charge['id'],
                'reviewed_by' => $this->actorId(), 'reviewed_at' => $now, 'review_note' => $note]);
            $this->audit('PAYMENT_REVIEW_MATCHED', 'payment_transaction', $id,
                ['match_status' => $t['match_status'], 'apartment_charge_id' => $t['apartment_charge_id']],
                ['match_status' => 'manual_matched', 'apartment_charge_id' => (int) $charge['id'], 'payment_ref' => PaymentStatusService::ref((int) $charge['id']), 'note' => $note]);

            return ['id' => $id, 'transaction_id' => (string) $t['transaction_id'], 'match_status' => 'manual_matched', 'duplicate' => false,
                'charge_id' => (int) $charge['id'], 'became_paid' => true, 'emails' => []];
        });

        return $this->afterCommit($result);
    }

    /** Kế toán bỏ qua giao dịch cần kiểm tra (đã xử lý ngoài hệ thống, vd hoàn tiền) - bắt buộc lý do. */
    public function ignore(int $id, string $note): void
    {
        $note = $this->requireNote($note);
        $this->transaction($this->payments, function () use ($id, $note): void {
            $t = $this->lockOpen($id);
            $this->payments->updateById($id, ['match_status' => 'ignored', 'reviewed_by' => $this->actorId(), 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $note]);
            $this->audit('PAYMENT_REVIEW_IGNORED', 'payment_transaction', $id, ['match_status' => $t['match_status']], ['match_status' => 'ignored', 'note' => $note]);
        });
    }

    // ------------------------------------------------------------------ nội bộ

    /** @return array{0:string, 1:?string, 2:bool} [match_status, ghi chú, chuyển sang PAID] */
    private function decide(?array $charge, int $amount): array
    {
        if ($charge === null) {
            return ['unknown_ref', 'Không tìm thấy mã thanh toán hợp lệ trong nội dung chuyển khoản.', false];
        }
        if (!PaymentStatusService::payable((string) $charge['period_status'], (string) $charge['status'])) {
            return ['not_payable', "Kỳ {$charge['period_code']} chưa khoá (hoặc đã mở lại) — số phải thu chưa chốt.", false];
        }
        $remaining = $this->remaining($charge);
        if ($remaining <= 0) {
            return ['already_paid', 'Bản tính đã được thanh toán đủ trước đó (có thể chuyển trùng).', false];
        }
        if ($amount !== $remaining) {
            return ['amount_mismatch', 'Số tiền ' . money_vnd(Decimal::fromCents($amount)) . ' khác số phải thu ' . money_vnd(Decimal::fromCents($remaining)) . '.', false];
        }

        return ['matched', null, true];
    }

    /** Số còn phải thu - đọc khoá (xem PaymentTransactionRepository::recordedForChargeLocked), gọi sau lockChargeForPayment(). */
    private function remaining(array $charge): int
    {
        return MoneyCalculator::fromDb($charge['total_due']) - MoneyCalculator::fromDb($this->payments->recordedForChargeLocked((int) $charge['id']));
    }

    private function duplicate(array $existing): array
    {
        return ['id' => (int) $existing['id'], 'transaction_id' => (string) $existing['transaction_id'], 'match_status' => (string) $existing['match_status'],
            'duplicate' => true, 'charge_id' => $existing['apartment_charge_id'] !== null ? (int) $existing['apartment_charge_id'] : null, 'became_paid' => false, 'emails' => []];
    }

    private function afterCommit(array $result): array
    {
        if ($result['became_paid'] && $result['charge_id'] !== null) {
            try {
                $result['emails'] = (new EmailNotificationService())->sendPaymentConfirmation((int) $result['charge_id'], (int) $result['id']);
            } catch (Throwable $e) {
                error_log('[payment] email xác nhận thanh toán giao dịch #' . $result['id'] . ': ' . $e->getMessage()); // giao dịch đã ghi nhận - không hoàn tác
            }
        }

        return $result;
    }

    private function lockOpen(int $id): array
    {
        $t = $this->payments->findForUpdate($id);
        if ($t === null) {
            throw ValidationException::withMessage('Giao dịch không tồn tại.');
        }
        if (!in_array($t['match_status'], PaymentTransactionRepository::OPEN_REVIEW, true)) {
            throw ValidationException::withMessage('Giao dịch đã được xử lý, vui lòng tải lại.');
        }

        return $t;
    }

    private function requireNote(string $note): string
    {
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessage('Vui lòng nhập lý do xử lý.', 'note');
        }
        if (mb_strlen($note) > 500) {
            throw ValidationException::withMessage('Lý do tối đa 500 ký tự.', 'note');
        }

        return $note;
    }

    /** @return array{transaction_id:string, amount_cents:int, content:string, payer_name:?string, received_at:string} */
    private function validatePayload(array $p): array
    {
        $errors = [];
        $txn = is_scalar($p['transaction_id'] ?? null) ? trim((string) $p['transaction_id']) : '';
        if (!preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $txn)) {
            $errors['transaction_id'][] = 'Mã giao dịch bắt buộc, 1–64 ký tự chữ / số / . _ -.';
        }
        $amountRaw = is_scalar($p['amount'] ?? null) ? (string) $p['amount'] : '';
        $cents = Decimal::toCents($amountRaw);
        if ($cents === null || $cents <= 0) {
            $errors['amount'][] = 'Số tiền phải là số dương, tối đa 2 chữ số thập phân.';
        }
        $content = is_scalar($p['content'] ?? null) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $p['content']) ?? '') : '';
        if (mb_strlen($content) > 255) {
            $errors['content'][] = 'Nội dung chuyển khoản tối đa 255 ký tự.';
        }
        $payer = is_scalar($p['payer_name'] ?? null) ? trim((string) $p['payer_name']) : '';
        if (mb_strlen($payer) > 150) {
            $errors['payer_name'][] = 'Tên người chuyển tối đa 150 ký tự.';
        }
        $at = is_scalar($p['received_at'] ?? null) ? trim((string) $p['received_at']) : '';
        $dt = $at === '' ? new \DateTimeImmutable() : \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $at);
        if ($dt === false) {
            $errors['received_at'][] = 'Thời điểm giao dịch phải dạng YYYY-MM-DD HH:MM:SS.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['transaction_id' => $txn, 'amount_cents' => (int) $cents, 'content' => $content, 'payer_name' => $payer === '' ? null : $payer,
            'received_at' => $dt->format('Y-m-d H:i:s')];
    }
}
