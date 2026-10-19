<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\PaymentCallbackException;
use App\Services\PaymentReconciliationService;

/**
 * Webhook ngân hàng (giả lập): POST /payments/callback, body JSON, header X-Signature = HMAC-SHA256(body, MOCK_BANK_SECRET).
 * Không dùng phiên đăng nhập / CSRF (máy gọi máy) - bảo vệ bằng: tắt mặc định (404), chữ ký HMAC (401), giới hạn kích thước (413).
 */
class PaymentCallbackController extends BaseController
{
    private const MAX_BODY = 8192;

    public function handle(): void
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            $this->json(['ok' => false, 'error' => 'Payload quá lớn.'], 413);
        }
        try {
            $r = (new PaymentReconciliationService())->handleCallback($raw, (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
            $this->json(['ok' => true, 'transaction_id' => $r['transaction_id'], 'match_status' => $r['match_status'], 'duplicate' => $r['duplicate'],
                'charge_ref' => $r['charge_id'] !== null ? \App\Services\PaymentStatusService::ref($r['charge_id']) : null]);
        } catch (PaymentCallbackException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        }
    }
}
