<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ZaloNotificationRepository;

/**
 * Gửi phiếu thông báo phí qua Zalo (ZNS), cắm cạnh luồng email FN-10.03 - cùng quy tắc:
 * phiếu phải Đã xác nhận trở lên + hiện hành (BillNoticeService), người nhận là cư dân ĐANG HIỆU LỰC của căn (ở đây: có SĐT di động hợp lệ),
 * ghi hộp thư đi "pending" (UNIQUE chống gửi trùng) -> gửi NGOÀI transaction -> cập nhật sent / failed + audit.
 * Không đổi trạng thái bản tính / kỳ. Nội dung chỉ gồm tên, căn hộ, kỳ, tổng phải thu, mã thanh toán (không chi tiết cá nhân khác).
 */
class ZaloNotificationService extends BaseService
{
    public const TYPE_BILL = 'bill_notice';
    public const PER_PAGE = 30;
    public const STALE_PENDING_MINUTES = 10;

    private ZaloNotificationRepository $notifications;
    private ZaloClient $client;

    public function __construct(?ZaloClient $client = null)
    {
        $this->notifications = new ZaloNotificationRepository();
        $this->client = $client ?? new ZaloClient();
    }

    /** Bảng zalo_notifications đã có trên DB hiện tại (đã chạy migration 20261018) chưa. */
    public function available(): bool
    {
        return $this->notifications->tableExists();
    }

    public function driver(): string
    {
        return $this->client->driver();
    }

    public function recipientsFor(array $notice): array
    {
        return $this->notifications->activeRecipients((int) $notice['charge']['apartment_id'], date('Y-m-d'));
    }

    public function historyForCharge(int $chargeId): array
    {
        return $this->notifications->forCharge($chargeId);
    }

    /** @return array{id:int, status:string, error:?string} */
    public function sendBillNotice(int $chargeId, string $residentId): array
    {
        $notice = (new BillNoticeService())->forCharge($chargeId); // ném lỗi nếu chưa xác nhận / CẦN TÍNH LẠI
        $c = $notice['charge'];
        if (!ctype_digit($residentId) || (int) $residentId < 1) {
            throw ValidationException::withMessage('Vui lòng chọn người nhận.', 'resident_id');
        }
        $recipient = $this->notifications->activeRecipients((int) $c['apartment_id'], date('Y-m-d'), (int) $residentId)[0] ?? null;
        if ($recipient === null) {
            throw ValidationException::withMessage('Người nhận không hợp lệ: không thuộc căn hộ, đã hết hiệu lực hoặc chưa có số điện thoại di động hợp lệ.', 'resident_id');
        }
        $templateId = $this->templateId();
        $phone = (string) $recipient['phone84'];
        $content = $this->render($notice, (string) $recipient['full_name']);
        $hash = $this->hash($templateId, $content);

        $id = $this->transaction($this->notifications, function () use ($c, $recipient, $phone, $templateId, $content, $hash, $notice): ?int {
            // Khoá kỳ + bản tính rồi kiểm tra lại nội dung: tránh gửi phiếu đã đổi số liệu
            (new BillingPeriodRepository())->findForUpdate((int) $c['billing_period_id']);
            (new ApartmentChargeRepository())->findForUpdate((int) $c['id']);
            $fresh = $this->render((new BillNoticeService())->forCharge((int) $c['id']), (string) $recipient['full_name']);
            if (!hash_equals($hash, $this->hash($templateId, $fresh))) {
                throw ValidationException::withMessage('Bản tính đã đổi trước khi gửi; tải lại phiếu.');
            }

            return $this->notifications->insertUnlessDuplicate([
                'notification_type' => self::TYPE_BILL,
                'resident_id' => (int) $recipient['id'],
                'apartment_id' => (int) $c['apartment_id'],
                'billing_period_id' => (int) $c['billing_period_id'],
                'apartment_charge_id' => (int) $c['id'],
                'recipient_phone' => $phone,
                'recipient_name' => mb_substr((string) $recipient['full_name'], 0, 150),
                'template_id' => $templateId,
                'template_data' => (string) json_encode($content['data'], JSON_UNESCAPED_UNICODE),
                'body_text' => $content['text'],
                'content_hash' => $hash,
                'tracking_id' => 'bill-' . (int) $c['id'] . '-' . substr($hash, 0, 12),
                'transport' => $this->client->driver(),
                'status' => 'pending',
                'attempt_count' => 1,
                'created_by' => $this->actorId(),
            ]);
        });
        if ($id === null) {
            $existing = $this->notifications->findByDedupe((int) $c['id'], $phone, self::TYPE_BILL, $hash);
            $when = $existing['sent_at'] ?? $existing['created_at'] ?? '';
            throw ValidationException::withMessage(match ($existing['status'] ?? '') {
                'sent' => "Phiếu này (cùng nội dung) đã được gửi Zalo tới người nhận này lúc {$when}. Không gửi trùng.",
                'failed' => 'Lần gửi Zalo trước tới người nhận này bị lỗi — dùng "Gửi lại" trong Hộp thư Zalo.',
                default => 'Tin Zalo này đang được gửi, vui lòng chờ.',
            });
        }

        return $this->deliver($id, false);
    }

    /** Gửi lại tin lỗi (hoặc kẹt pending quá STALE_PENDING_MINUTES). Tin đã gửi không gửi lại. */
    public function retry(int $id): array
    {
        $this->transaction($this->notifications, function () use ($id): void {
            $plain = $this->notifications->find($id);
            if ($plain !== null && $plain['apartment_charge_id'] !== null) {
                $charge = (new ApartmentChargeRepository())->find((int) $plain['apartment_charge_id']);
                if ($charge !== null) {
                    (new BillingPeriodRepository())->findForUpdate((int) $charge['billing_period_id']);
                    (new ApartmentChargeRepository())->findForUpdate((int) $charge['id']);
                }
            }
            $n = $this->notifications->findForUpdate($id);
            if ($n === null) {
                throw ValidationException::withMessage('Tin Zalo không tồn tại.');
            }
            $stale = $n['status'] === 'pending' && strtotime((string) $n['updated_at']) < time() - self::STALE_PENDING_MINUTES * 60;
            if ($n['status'] === 'sent') {
                throw ValidationException::withMessage('Tin đã gửi thành công — không gửi lại.');
            }
            if ($n['status'] !== 'failed' && !$stale) {
                throw ValidationException::withMessage('Tin đang được gửi — không thể gửi lại lúc này.');
            }
            if ($n['apartment_charge_id'] !== null) {
                $content = $this->render((new BillNoticeService())->forCharge((int) $n['apartment_charge_id']), (string) $n['recipient_name']);
                if (!hash_equals((string) $n['content_hash'], $this->hash((string) $n['template_id'], $content))) {
                    throw ValidationException::withMessage('Số liệu phiếu đã thay đổi so với lúc tạo tin — hãy gửi tin mới từ màn hình phiếu.');
                }
            }
            $this->notifications->updateById($id, ['status' => 'pending', 'attempt_count' => min(255, (int) $n['attempt_count'] + 1), 'last_error' => null]);
            $this->audit('RETRY_ZALO_NOTIFICATION', 'zalo_notification', $id, ['status' => $n['status'], 'attempt_count' => (int) $n['attempt_count']],
                ['attempt_count' => (int) $n['attempt_count'] + 1, 'recipient' => ZaloClient::maskPhone((string) $n['recipient_phone'])]);
        });

        return $this->deliver($id, true);
    }

    public function search(array $filters, int $page): array
    {
        $errors = [];
        foreach (['date_from', 'date_to'] as $k) {
            $d = ($filters[$k] ?? '') !== '' ? \DateTime::createFromFormat('!Y-m-d', $filters[$k]) : null;
            if (($filters[$k] ?? '') !== '' && ($d === false || $d->format('Y-m-d') !== $filters[$k])) {
                $errors[$k][] = 'Ngày không hợp lệ.';
            }
        }
        if (($filters['status'] ?? '') !== '' && !in_array($filters['status'], ['pending', 'sent', 'failed'], true)) {
            $errors['status'][] = 'Trạng thái không hợp lệ.';
        }
        if ($errors === [] && ($filters['date_from'] ?? '') !== '' && ($filters['date_to'] ?? '') !== '' && $filters['date_from'] > $filters['date_to']) {
            $errors['date_to'][] = 'Từ ngày phải nhỏ hơn hoặc bằng đến ngày.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->notifications->search($filters, $page, self::PER_PAGE);
    }

    public function find(int $id): ?array
    {
        return $this->notifications->findDetailed($id);
    }

    /** Gửi bản ghi đang pending rồi ghi kết quả + audit (transaction ngắn, gửi mạng nằm NGOÀI transaction). */
    private function deliver(int $id, bool $isRetry): array
    {
        $n = $this->notifications->find($id);
        $error = null;
        $msgId = '';
        try {
            $msgId = $this->client->send((string) $n['recipient_phone'], (string) $n['template_id'],
                (array) json_decode((string) $n['template_data'], true), (string) $n['tracking_id']);
        } catch (ZaloTransportException $e) {
            $error = ZaloClient::sanitize($e->getMessage());
        }
        $status = $error === null ? 'sent' : 'failed';
        $this->transaction($this->notifications, function () use ($id, $n, $status, $error, $msgId, $isRetry): void {
            $this->notifications->updateById($id, $status === 'sent'
                ? ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'last_error' => null, 'zalo_msg_id' => $msgId !== '' ? $msgId : null, 'transport' => $this->client->driver()]
                : ['status' => 'failed', 'last_error' => $error]);
            $payload = ['notification_id' => $id, 'apartment_charge_id' => $n['apartment_charge_id'], 'recipient' => ZaloClient::maskPhone((string) $n['recipient_phone']),
                'transport' => $this->client->driver(), 'attempt' => (int) $n['attempt_count'], 'retry' => $isRetry];
            $this->audit($status === 'sent' ? 'SEND_ZALO_NOTIFICATION' : 'ZALO_NOTIFICATION_FAILED', 'zalo_notification', $id, null,
                $status === 'sent' ? $payload : $payload + ['error' => $error]);
        });

        return ['id' => $id, 'status' => $status, 'error' => $error];
    }

    private function templateId(): string
    {
        $tpl = (string) config('zalo.template_bill', '');
        if ($tpl === '') {
            if ($this->client->driver() === 'zns') {
                throw ValidationException::withMessage('Chưa cấu hình mẫu tin Zalo: điền ZALO_TEMPLATE_BILL trong .env.');
            }
            $tpl = 'DEMO-TEMPLATE'; // driver log / array: chạy demo không cần mẫu thật
        }

        return mb_substr($tpl, 0, 40);
    }

    /** @param array{data:array<string,string>, text:string} $content */
    private function hash(string $templateId, array $content): string
    {
        return hash('sha256', $templateId . "\n" . json_encode($content['data'], JSON_UNESCAPED_UNICODE) . "\n" . $content['text']);
    }

    /**
     * Nội dung tin: tham số mẫu ZNS (đúng tên tham số theo config/zalo.php, mọi giá trị là chuỗi, cắt theo giới hạn độ dài)
     * + bản đọc được để lưu / xem lại.
     * @return array{data:array<string,string>, text:string}
     */
    public function render(array $notice, string $recipientName): array
    {
        $c = $notice['charge'];
        $values = [
            'customer_name' => $recipientName,
            'apartment' => (string) $c['apartment_code'],
            'period' => (string) $c['period_code'],
            'total_due' => money_vnd($c['total_due']),
            'payment_ref' => PaymentStatusService::ref((int) $c['id']),
        ];
        $max = (array) config('zalo.param_max_length', []);
        $data = [];
        foreach ((array) config('zalo.bill_params', []) as $key => $paramName) {
            if (isset($values[$key]) && $paramName !== '') {
                $data[(string) $paramName] = mb_substr($values[$key], 0, (int) ($max[$key] ?? 100));
            }
        }
        $text = "Kính gửi {$recipientName}, Ban Quản lý thông báo phí căn hộ {$c['building_code']} / {$c['apartment_code']} — kỳ {$c['period_code']}. "
            . 'Tổng phải thu: ' . $values['total_due'] . '. Mã thanh toán (ghi vào nội dung chuyển khoản): ' . $values['payment_ref'] . '.';

        return ['data' => $data, 'text' => $text];
    }
}
