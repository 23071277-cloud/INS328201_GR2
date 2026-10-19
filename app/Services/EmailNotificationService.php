<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\EmailNotificationRepository;

/**
 * STEP 25 - FN-10.03 (Secondary) gửi thông báo phí qua email. Assumption A-EMAIL-01…07 (docs/STEP2-3_design.md mục 19).
 * Luồng: kiểm tra phiếu (cùng điều kiện FN-09.02: Đã xác nhận trở lên + hiện hành) → kiểm tra người nhận (cư dân đang hiệu lực
 * của căn, có email) → dựng nội dung → ghi hộp thư đi "pending" (UNIQUE chống trùng, transaction ngắn) → gửi qua EmailService
 * (NGOÀI transaction - không giữ khoá DB khi chờ mạng) → cập nhật sent / failed + audit trong transaction.
 * Không đổi trạng thái bản tính / kỳ (không "phát hành" - J-19).
 */
class EmailNotificationService extends BaseService
{
    public const TYPE_BILL = 'bill_notice';
    /** [BA Proposal J-16] email xác minh tiêu thụ gửi cư dân (không dùng mẫu phiếu phí). */
    public const TYPE_CONSUMPTION_VERIFICATION = 'consumption_verification';
    /** Payment module: email xác nhận đã nhận thanh toán (gửi tự động 1 lần / bản tính khi UNPAID -> PAID). */
    public const TYPE_PAYMENT = 'payment_confirmation';
    public const PER_PAGE = 30;
    /** Bản ghi kẹt ở pending lâu hơn (vd tiến trình chết giữa chừng) được coi như lỗi và cho gửi lại. */
    public const STALE_PENDING_MINUTES = 10;

    private EmailNotificationRepository $notifications;
    private EmailService $mailer;

    public function __construct(?EmailService $mailer = null)
    {
        $this->notifications = new EmailNotificationRepository();
        $this->mailer = $mailer ?? new EmailService();
    }

    /** Người nhận có thể chọn cho một phiếu (cư dân đang hiệu lực của căn, có email). */
    public function recipientsFor(array $notice): array
    {
        return $this->notifications->activeRecipients((int) $notice['charge']['apartment_id'], date('Y-m-d'));
    }

    public function historyForCharge(int $chargeId): array
    {
        return $this->notifications->forCharge($chargeId);
    }

    /**
     * Gửi phiếu thông báo phí của bản tính $chargeId tới cư dân $residentId.
     * @return array{id:int, status:string, error:?string}
     */
    public function sendBillNotice(int $chargeId, string $residentId): array
    {
        $notice = (new BillNoticeService())->forCharge($chargeId); // ném lỗi nếu chưa xác nhận / CẦN TÍNH LẠI
        $c = $notice['charge'];
        if (!ctype_digit($residentId) || (int) $residentId < 1) {
            throw ValidationException::withMessage('Vui lòng chọn người nhận.', 'resident_id');
        }
        $recipient = $this->notifications->activeRecipients((int) $c['apartment_id'], date('Y-m-d'), (int) $residentId)[0] ?? null;
        if ($recipient === null) {
            throw ValidationException::withMessage('Người nhận không hợp lệ: không thuộc căn hộ, đã hết hiệu lực hoặc chưa có email.', 'resident_id');
        }
        $email = strtolower(trim((string) $recipient['email']));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email)) {
            throw ValidationException::withMessage('Email của người nhận không hợp lệ — vui lòng sửa ở thông tin cư dân.', 'resident_id');
        }

        $content = $this->render($notice, (string) $recipient['full_name']);
        $hash = hash('sha256', $content['subject'] . "\n" . $content['html'] . "\n" . $content['text']);

        $id = $this->transaction($this->notifications, function () use ($c, $recipient, $email, $content, $hash): ?int {
            // Serialize issuance with charge mutation and revalidate content after locking.
            (new \App\Repositories\BillingPeriodRepository())->findForUpdate((int)$c['billing_period_id']);
            (new \App\Repositories\ApartmentChargeRepository())->findForUpdate((int)$c['id']);
            $freshNotice = (new BillNoticeService())->forCharge((int)$c['id']);
            $freshContent = $this->render($freshNotice, (string)$recipient['full_name']);
            if (!hash_equals($hash, hash('sha256', $freshContent['subject']."\n".$freshContent['html']."\n".$freshContent['text']))) {
                throw ValidationException::withMessage('Bản tính đã đổi trước khi gửi; tải lại phiếu.');
            }
            return $this->notifications->insertUnlessDuplicate([
                'notification_type' => self::TYPE_BILL,
                'resident_id' => (int) $recipient['id'],
                'apartment_id' => (int) $c['apartment_id'],
                'billing_period_id' => (int) $c['billing_period_id'],
                'apartment_charge_id' => (int) $c['id'],
                'recipient_email' => $email,
                'recipient_name' => mb_substr((string) $recipient['full_name'], 0, 150),
                'subject' => $content['subject'],
                'body_html' => $content['html'],
                'body_text' => $content['text'],
                'content_hash' => $hash,
                'transport' => $this->mailer->driver(),
                'status' => 'pending',
                'attempt_count' => 1,
                'created_by' => $this->actorId(),
            ]);
        });
        if ($id === null) {
            $existing = $this->notifications->findByDedupe((int) $c['id'], $email, self::TYPE_BILL, $hash);
            $when = $existing['sent_at'] ?? $existing['created_at'] ?? '';
            throw ValidationException::withMessage(match ($existing['status'] ?? '') {
                'sent' => "Phiếu này (cùng nội dung) đã được gửi tới người nhận này lúc {$when}. Không gửi trùng.",
                'failed' => 'Lần gửi trước tới người nhận này bị lỗi — dùng "Gửi lại" trong hộp thư đi.',
                default => 'Thông báo này đang được gửi, vui lòng chờ.',
            });
        }

        return $this->deliver($id, false);
    }

    /**
     * [BA Proposal J-16] BQL gửi email xác minh tiêu thụ cho cư dân đang hiệu lực (có email) của căn trong cảnh báo.
     * Nội dung trung tính, không kết luận nguyên nhân, không dữ liệu cá nhân ngoài tên người nhận. Chống trùng uq_email_alert_dedupe.
     * @return array{id:int, status:string, error:?string}
     */
    public function sendConsumptionVerification(int $alertId, string $residentId): array
    {
        $alert = (new \App\Repositories\AlertRepository())->findDetailed($alertId);
        if ($alert === null || !NoUsageConsumptionService::isJ16($alert)) {
            throw ValidationException::withMessage('Cảnh báo không tồn tại hoặc không phải loại căn khai báo không sử dụng.');
        }
        if ($alert['status'] === 'da_dong') {
            throw ValidationException::withMessage('Cảnh báo đã đóng. Vui lòng mở lại trước khi thay đổi.');
        }
        if (!ctype_digit($residentId) || (int) $residentId < 1) {
            throw ValidationException::withMessage('Vui lòng chọn người nhận.', 'resident_id');
        }
        $recipient = $this->notifications->activeRecipients((int) $alert['apartment_id'], date('Y-m-d'), (int) $residentId)[0] ?? null;
        if ($recipient === null) {
            throw ValidationException::withMessage('Người nhận không hợp lệ: không thuộc căn hộ, đã hết hiệu lực hoặc chưa có email.', 'resident_id');
        }
        $email = strtolower(trim((string) $recipient['email']));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email)) {
            throw ValidationException::withMessage('Email của người nhận không hợp lệ — vui lòng sửa ở thông tin cư dân.', 'resident_id');
        }
        $snapshot = json_decode((string) $alert['snapshot_json'], true) ?: [];
        $content = $this->renderConsumptionVerification($alert, $snapshot, (string) $recipient['full_name']);
        $hash = hash('sha256', $content['subject'] . "\n" . $content['html'] . "\n" . $content['text']);

        $id = $this->transaction($this->notifications, function () use ($alert, $recipient, $email, $content, $hash): ?int {
            return $this->notifications->insertUnlessDuplicate([
                'notification_type' => self::TYPE_CONSUMPTION_VERIFICATION,
                'resident_id' => (int) $recipient['id'],
                'apartment_id' => (int) $alert['apartment_id'],
                'billing_period_id' => (int) $alert['billing_period_id'],
                'apartment_charge_id' => null,
                'alert_id' => (int) $alert['id'],
                'recipient_email' => $email,
                'recipient_name' => mb_substr((string) $recipient['full_name'], 0, 150),
                'subject' => $content['subject'],
                'body_html' => $content['html'],
                'body_text' => $content['text'],
                'content_hash' => $hash,
                'transport' => $this->mailer->driver(),
                'status' => 'pending',
                'attempt_count' => 1,
                'created_by' => $this->actorId(),
            ]);
        });
        if ($id === null) {
            $existing = $this->notifications->findByAlertDedupe((int) $alert['id'], $email, self::TYPE_CONSUMPTION_VERIFICATION, $hash);
            $when = $existing['sent_at'] ?? $existing['created_at'] ?? '';
            throw ValidationException::withMessage(match ($existing['status'] ?? '') {
                'sent' => "Email xác minh (cùng nội dung) đã được gửi tới người nhận này lúc {$when}. Không gửi trùng.",
                'failed' => 'Lần gửi trước tới người nhận này bị lỗi — dùng "Gửi lại" trong hộp thư đi.',
                default => 'Email này đang được gửi, vui lòng chờ.',
            });
        }

        return $this->deliver($id, false);
    }

    /**
     * Nội dung email xác minh J-16 - trung tính (không "rò điện / gian lận / hỏng công tơ"), mọi giá trị escape trong HTML.
     * @return array{subject:string, html:string, text:string}
     */
    public function renderConsumptionVerification(array $alert, array $snapshot, string $recipientName): array
    {
        $units = config('pricing.utility_unit');
        $subject = '[Energy DSS] Yêu cầu xác minh tiêu thụ điện/nước - Căn ' . $alert['apartment_code'] . ' - kỳ ' . $alert['period_code'];
        $subject = preg_replace('/[\r\n\t]+/', ' ', mb_substr($subject, 0, 250)) ?? '';
        $data = [
            'name' => $recipientName,
            'apartment' => (string) $alert['apartment_code'],
            'period' => (string) $alert['period_code'],
            'electricity' => format_decimal($snapshot['electricity_consumption'] ?? '0') . ' ' . $units['dien'],
            'water' => format_decimal($snapshot['water_consumption'] ?? '0') . ' ' . $units['nuoc'],
        ];
        ob_start();
        require BASE_PATH . '/resources/views/emails/consumption_verification.php';
        $html = (string) ob_get_clean();
        $text = implode("\n", [
            "Kính gửi {$recipientName},", '',
            "Ban Quản lý ghi nhận căn hộ {$data['apartment']} hiện đang được khai báo ở trạng thái 'Không phát sinh điện nước'. Tuy nhiên, dữ liệu công tơ kỳ {$data['period']} ghi nhận mức tiêu thụ:", '',
            "- Điện: {$data['electricity']}",
            "- Nước: {$data['water']}", '',
            'Ban Quản lý đang thực hiện kiểm tra lại chỉ số và tình trạng công tơ.', '',
            'Đây chưa phải kết luận về nguyên nhân phát sinh tiêu thụ.', '',
            'Nếu căn hộ hiện có người sử dụng, sửa chữa, cho thuê hoặc có hoạt động liên quan đến điện/nước, vui lòng phản hồi Ban Quản lý để hỗ trợ xác minh.', '',
            'Email gửi tự động từ hệ thống Energy DSS theo yêu cầu của Ban Quản lý. Vui lòng không trả lời email này; xin liên hệ trực tiếp Ban Quản lý.',
        ]);

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /**
     * Payment module: email xác nhận đã nhận thanh toán tới cư dân đang hiệu lực (có email) của căn. CHỈ 1 LẦN / bản tính:
     * đã có thư payment_confirmation cho bản tính (bất kỳ trạng thái - thư lỗi dùng "Gửi lại") thì không tạo thêm.
     * Gọi SAU khi giao dịch thanh toán đã commit (PaymentReconciliationService); lỗi gửi chỉ ghi vào hộp thư đi.
     * @return list<array{id:int, status:string, error:?string}>
     */
    public function sendPaymentConfirmation(int $chargeId, int $transactionRowId): array
    {
        if ($this->notifications->existsForChargeType($chargeId, self::TYPE_PAYMENT)) {
            return [];
        }
        $t = (new \App\Repositories\PaymentTransactionRepository())->findDetailed($transactionRowId);
        $c = (new \App\Repositories\ApartmentChargeRepository())->find($chargeId);
        if ($t === null || $c === null || (int) $t['apartment_charge_id'] !== $chargeId) {
            return [];
        }
        $out = [];
        foreach ($this->notifications->activeRecipients((int) $c['apartment_id'], date('Y-m-d')) as $recipient) {
            $email = strtolower(trim((string) $recipient['email']));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email)) {
                continue;
            }
            $content = $this->renderPaymentConfirmation($t, (string) $recipient['full_name']);
            $hash = hash('sha256', $content['subject'] . "\n" . $content['html'] . "\n" . $content['text']);
            $id = $this->transaction($this->notifications, function () use ($c, $recipient, $email, $content, $hash, $chargeId): ?int {
                return $this->notifications->insertUnlessDuplicate([
                    'notification_type' => self::TYPE_PAYMENT,
                    'resident_id' => (int) $recipient['id'],
                    'apartment_id' => (int) $c['apartment_id'],
                    'billing_period_id' => (int) $c['billing_period_id'],
                    'apartment_charge_id' => $chargeId,
                    'recipient_email' => $email,
                    'recipient_name' => mb_substr((string) $recipient['full_name'], 0, 150),
                    'subject' => $content['subject'],
                    'body_html' => $content['html'],
                    'body_text' => $content['text'],
                    'content_hash' => $hash,
                    'transport' => $this->mailer->driver(),
                    'status' => 'pending',
                    'attempt_count' => 1,
                    'created_by' => $this->actorId(),
                ]);
            });
            if ($id !== null) {
                $out[] = $this->deliver($id, false);
            }
        }

        return $out;
    }

    /**
     * Nội dung email xác nhận thanh toán (chỉ dữ liệu giao dịch + bản tính; không SĐT / dữ liệu cư dân khác). Mọi giá trị escape trong HTML.
     * @return array{subject:string, html:string, text:string}
     */
    public function renderPaymentConfirmation(array $t, string $recipientName): array
    {
        $apartment = $t['building_code'] . ' / ' . $t['apartment_code'];
        $subject = '[Energy DSS] Xác nhận thanh toán kỳ ' . $t['period_code'] . ' - Căn ' . $t['apartment_code'];
        $subject = preg_replace('/[\r\n\t]+/', ' ', mb_substr($subject, 0, 250)) ?? '';
        $data = [
            'name' => $recipientName, 'apartment' => $apartment, 'period_code' => (string) $t['period_code'],
            'amount' => money_vnd($t['amount']), 'transaction_id' => (string) $t['transaction_id'],
            'received_at' => date('d/m/Y H:i', strtotime((string) $t['received_at'])),
            'ref' => \App\Services\PaymentStatusService::ref((int) $t['apartment_charge_id']),
            'header_src' => 'cid:header-visual',
        ];
        ob_start();
        require BASE_PATH . '/resources/views/emails/payment_confirmation.php';
        $html = (string) ob_get_clean();
        $text = implode("\n", [
            "Kính gửi {$recipientName},", '',
            "Ban Quản lý xác nhận đã nhận thanh toán phí căn hộ {$apartment} — kỳ {$data['period_code']}:", '',
            '- Số tiền: ' . $data['amount'],
            '- Mã thanh toán: ' . $data['ref'],
            '- Mã giao dịch: ' . $data['transaction_id'],
            '- Thời gian ghi nhận: ' . $data['received_at'],
            '', 'Trạng thái: ĐÃ THANH TOÁN. Cảm ơn Quý cư dân.', '',
            'Email gửi tự động từ hệ thống Energy DSS theo yêu cầu của Ban Quản lý. Vui lòng không trả lời email này; mọi thắc mắc xin liên hệ Ban Quản lý.',
        ]);

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** Gửi lại thông báo bị lỗi (hoặc kẹt pending quá STALE_PENDING_MINUTES). Bản đã gửi không gửi lại. */
    public function retry(int $id): array
    {
        $this->transaction($this->notifications, function () use ($id): void {
            $plain = $this->notifications->find($id);
            if ($plain !== null && $plain['notification_type'] === self::TYPE_BILL && $plain['apartment_charge_id'] !== null) {
                $charge = (new \App\Repositories\ApartmentChargeRepository())->find((int)$plain['apartment_charge_id']);
                if ($charge !== null) {
                    (new \App\Repositories\BillingPeriodRepository())->findForUpdate((int)$charge['billing_period_id']);
                    (new \App\Repositories\ApartmentChargeRepository())->findForUpdate((int)$charge['id']);
                }
            }
            $n = $this->notifications->findForUpdate($id);
            if ($n === null) {
                throw ValidationException::withMessage('Thông báo không tồn tại.');
            }
            $stale = $n['status'] === 'pending' && strtotime((string) $n['updated_at']) < time() - self::STALE_PENDING_MINUTES * 60;
            if ($n['status'] === 'sent') {
                throw ValidationException::withMessage('Thông báo đã gửi thành công — không gửi lại.');
            }
            if ($n['status'] !== 'failed' && !$stale) {
                throw ValidationException::withMessage('Thông báo đang được gửi — không thể gửi lại lúc này.');
            }
            if ($n['notification_type'] === self::TYPE_BILL && $n['apartment_charge_id'] !== null) {
                // Nội dung phiếu phải còn đúng: bản tính vẫn Đã xác nhận + hiện hành và không đổi số liệu
                $notice = (new BillNoticeService())->forCharge((int) $n['apartment_charge_id']);
                $content = $this->render($notice, (string) $n['recipient_name']);
                if (!hash_equals((string) $n['content_hash'], hash('sha256', $content['subject'] . "\n" . $content['html'] . "\n" . $content['text']))) {
                    throw ValidationException::withMessage('Số liệu phiếu đã thay đổi so với lúc tạo thông báo — hãy gửi thông báo mới từ màn hình phiếu.');
                }
            }
            $this->notifications->updateById($id, ['status' => 'pending', 'attempt_count' => min(255, (int) $n['attempt_count'] + 1), 'last_error' => null]);
            $this->audit('RETRY_EMAIL_NOTIFICATION', 'email_notification', $id, ['status' => $n['status'], 'attempt_count' => (int) $n['attempt_count']],
                ['attempt_count' => (int) $n['attempt_count'] + 1, 'recipient' => mask_email($n['recipient_email'])]);
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

    /** Gửi bản ghi đã ở trạng thái pending, rồi ghi kết quả + audit (transaction ngắn). */
    private function deliver(int $id, bool $isRetry): array
    {
        $n = $this->notifications->find($id);
        $error = null;
        try {
            $this->mailer->send((string) $n['recipient_email'], (string) $n['recipient_name'], (string) $n['subject'], (string) $n['body_html'], (string) $n['body_text'],
                self::inlineImages((string) $n['body_html']));
        } catch (EmailTransportException $e) {
            $error = EmailService::sanitize($e->getMessage());
        }
        $status = $error === null ? 'sent' : 'failed';
        $this->transaction($this->notifications, function () use ($id, $n, $status, $error, $isRetry): void {
            $this->notifications->updateById($id, $status === 'sent'
                ? ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'last_error' => null, 'transport' => $this->mailer->driver()]
                : ['status' => 'failed', 'last_error' => $error]);
            $payload = ['notification_id' => $id, 'apartment_charge_id' => $n['apartment_charge_id'], 'recipient' => mask_email((string) $n['recipient_email']),
                'transport' => $this->mailer->driver(), 'attempt' => (int) $n['attempt_count'], 'retry' => $isRetry];
            $j16 = $n['notification_type'] === self::TYPE_CONSUMPTION_VERIFICATION;
            $sentAction = $j16 ? 'SEND_CONSUMPTION_VERIFICATION_EMAIL' : 'SEND_EMAIL_NOTIFICATION';
            $this->audit($status === 'sent' ? $sentAction : 'EMAIL_NOTIFICATION_FAILED', 'email_notification', $id, null,
                ($status === 'sent' ? $payload : $payload + ['error' => $error]) + ($j16 ? ['alert_id' => (int) $n['alert_id'], 'type' => $n['notification_type']] : []));
            if ($j16 && $n['alert_id'] !== null) {
                $alerts = new \App\Repositories\AlertRepository();
                $alert = $alerts->find((int) $n['alert_id']);
                if ($alert !== null) {
                    $alerts->insertHistory((int) $alert['id'], $alert['status'], $alert['status'], null, null, 'Email xác minh tới ' . mask_email((string) $n['recipient_email'])
                        . ($status === 'sent' ? ' — đã gửi' : ' — LỖI: ' . mb_substr((string) $error, 0, 200)) . ($isRetry ? ' (gửi lại)' : ''), $this->actorId());
                }
            }
        });

        return ['id' => $id, 'status' => $status, 'error' => $error];
    }

    /**
     * Ảnh nhúng (cid) cần gửi kèm theo nội dung đã lưu: ảnh trong config/email_images.php mà HTML có tham chiếu (src="cid:<id>").
     * @return array<string,string> Content-ID => đường dẫn file
     */
    public static function inlineImages(string $html): array
    {
        $out = [];
        foreach ((array) config('email_images', []) as $cid => $file) {
            if (str_contains($html, 'cid:' . $cid)) {
                $out[(string) $cid] = BASE_PATH . '/' . $file;
            }
        }

        return $out;
    }

    /**
     * Nội dung email: chỉ thông tin phiếu (không SĐT, không dữ liệu cư dân khác - BR-36). Mọi giá trị được escape trong HTML.
     * @return array{subject:string, html:string, text:string}
     */
    public function render(array $notice, string $recipientName): array
    {
        $c = $notice['charge'];
        $apartment = $c['building_code'] . ' / ' . $c['apartment_code'];
        $subject = '[Energy DSS] Thông báo phí kỳ ' . $c['period_code'] . ' - Căn ' . $c['apartment_code'];
        $subject = preg_replace('/[\r\n\t]+/', ' ', mb_substr($subject, 0, 250)) ?? '';
        $units = config('pricing.utility_unit');
        $electricity = (string) \App\Core\Decimal::fromCents(MoneyCalculator::fromDb($c['electricity_amount']) + MoneyCalculator::fromDb($c['electricity_vat_amount']));
        $rows = [
            ['Tiền điện (' . format_decimal($c['electricity_consumption']) . ' ' . $units['dien'] . ', gồm VAT)', money_vnd($electricity)],
            ['Tiền nước (' . format_decimal($c['water_consumption']) . ' ' . $units['nuoc'] . ')', money_vnd($c['water_amount'])],
            ['Phí dịch vụ', money_vnd($c['service_fee_amount'])],
            ['Bảo hiểm cháy nổ', money_vnd($c['fire_insurance_amount'])],
            ['Phí gửi xe', money_vnd($c['parking_fee_amount'])],
            ['Tổng phát sinh', money_vnd($c['total_arising'])],
            ['Còn nợ tháng trước', money_vnd($c['old_debt_amount'])],
        ];
        $period = 'Kỳ ' . $c['period_code'] . ' (' . format_date($c['start_date']) . ' – ' . format_date($c['end_date']) . ')';
        $data = ['name' => $recipientName, 'apartment' => $apartment, 'period' => $period, 'rows' => $rows,
            'total' => money_vnd($c['total_due']), 'pay' => config('payment'), 'qr_src' => 'cid:' . config('payment.qr_cid'), 'header_src' => 'cid:header-visual',
            'period_code' => (string) $c['period_code'], 'apartment_code' => (string) $c['apartment_code'], 'building_code' => (string) $c['building_code']];

        ob_start();
        require BASE_PATH . '/resources/views/emails/bill_notice.php';
        $html = (string) ob_get_clean();

        $lines = ["Kính gửi {$recipientName},", '', "Ban Quản lý gửi thông báo phí căn hộ {$apartment} — {$period}:", ''];
        foreach ($rows as [$label, $value]) {
            $lines[] = "- {$label}: {$value}";
        }
        $lines[] = '';
        $lines[] = 'TỔNG PHẢI THU: ' . $data['total'];
        $lines[] = 'Quét QR để thanh toán (mã QR trong email), hoặc chuyển khoản:';
        $lines[] = '- Chủ tài khoản: ' . $data['pay']['account_name'];
        $lines[] = '- Số tài khoản: ' . $data['pay']['account_number_masked'];
        $lines[] = '- Ngân hàng: ' . $data['pay']['bank'];
        $lines[] = '';
        $lines[] = 'Email gửi tự động từ hệ thống Energy DSS theo yêu cầu của Ban Quản lý. Vui lòng không trả lời email này; mọi thắc mắc xin liên hệ Ban Quản lý.';

        return ['subject' => $subject, 'html' => $html, 'text' => implode("\n", $lines)];
    }
}
