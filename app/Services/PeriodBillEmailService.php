<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\EmailNotificationRepository;
use Throwable;

/**
 * FN-10.03 - gửi phiếu thông báo phí qua Gmail THEO KỲ cho các hộ: Kế toán / BQL bấm "Gửi Gmail cho các hộ" trên kỳ ĐÃ KHÓA
 * (gửi hàng loạt thủ công; khoá kỳ KHÔNG tự gửi) + "Gửi lại thư lỗi".
 * Tái dùng EmailNotificationService::sendBillNotice (kiểm tra phiếu FN-09.02, người nhận đang hiệu lực, chống trùng
 * uq_email_dedupe, transport PHPMailer / log / array, audit) và ::retry. Không đổi FN-05, không đổi schema:
 *   - "Thiếu email" KHÔNG lưu vào email_notifications (recipient_email NOT NULL): tính động từ người nhận hiện có + ghi trong audit tổng hợp;
 *   - gửi là thao tác RIÊNG sau khi kỳ đã khoá (transaction khoá đã commit từ trước) - lỗi gửi email không bao giờ đổi trạng thái khoá kỳ.
 * [ASSUMPTION A-EMAIL-08] Gửi THỦ CÔNG theo kỳ, chỉ yêu cầu kỳ Đã khóa; không phụ thuộc end_date hay ngày hiện tại.
 */
class PeriodBillEmailService extends BaseService
{
    private BillingPeriodRepository $periods;
    private ApartmentChargeRepository $charges;
    private EmailNotificationRepository $notifications;
    private EmailNotificationService $mail;

    public function __construct(?EmailNotificationService $mail = null)
    {
        $this->periods = new BillingPeriodRepository();
        $this->charges = new ApartmentChargeRepository();
        $this->notifications = new EmailNotificationRepository();
        $this->mail = $mail ?? new EmailNotificationService();
    }

    /**
     * Gửi phiếu cho mọi bản tính đủ điều kiện của kỳ đã khoá tới mọi chủ hộ / khách thuê đang hiệu lực có email.
     * Không gửi trùng (cùng phiếu + người nhận + nội dung). Lỗi từng thư không dừng các thư khác.
     * @return array{charges:int, sent:int, failed:int, already:int, missing:string[], not_eligible:array<string,string>}
     */
    public function sendForPeriod(int $periodId, string $trigger): array
    {
        $period = $this->periods->find($periodId);
        if ($period === null) {
            throw ValidationException::withMessage('Kỳ không tồn tại.');
        }
        if ($period['status'] !== 'da_khoa') {
            throw ValidationException::withMessage('Chỉ gửi phiếu theo kỳ sau khi kỳ đã khóa.');
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(300); // gửi tuần tự qua SMTP có thể lâu với kỳ nhiều căn
        }
        $sum = ['charges' => 0, 'sent' => 0, 'failed' => 0, 'already' => 0, 'missing' => [], 'not_eligible' => []];
        foreach ($this->eligibleCharges($periodId) as $charge) {
            $code = $charge['building_code'] . ' / ' . $charge['apartment_code'];
            try {
                (new BillNoticeService())->forCharge((int) $charge['id']); // cùng điều kiện phiếu FN-09.02 (Đã xác nhận + hiện hành)
            } catch (ValidationException $e) {
                $sum['not_eligible'][$code] = $e->getMessage();
                continue;
            }
            $sum['charges']++;
            $recipients = $this->notifications->activeRecipients((int) $charge['apartment_id'], date('Y-m-d'));
            if ($recipients === []) {
                $sum['missing'][] = $code;
                continue;
            }
            foreach ($recipients as $r) {
                try {
                    $res = $this->mail->sendBillNotice((int) $charge['id'], (string) $r['id']);
                    $res['status'] === 'sent' ? $sum['sent']++ : $sum['failed']++;
                } catch (ValidationException $e) {
                    // trùng: đã gửi -> không gửi lại; lần trước lỗi -> vẫn là lỗi chờ Retry
                    if (str_contains($e->getMessage(), 'Không gửi trùng')) {
                        $sum['already']++;
                    } elseif (str_contains($e->getMessage(), 'bị lỗi')) {
                        $sum['failed']++;
                    } else {
                        $sum['not_eligible'][$code . ' → ' . mask_email((string) $r['email'])] = $e->getMessage();
                    }
                } catch (Throwable $e) {
                    error_log('[bill-email] kỳ ' . $periodId . ' bản tính ' . $charge['id'] . ': ' . $e->getMessage());
                    $sum['failed']++;
                }
            }
        }
        // audit tổng hợp: chỉ số đếm + mã căn thiếu email (không ghi địa chỉ email)
        $this->audit('SEND_PERIOD_BILL_EMAILS', 'billing_period', $periodId, null,
            ['trigger' => $trigger, 'transport' => (new EmailService())->driver()] + $sum);

        return $sum;
    }

    /** Gửi lại mọi thư LỖI (bản ghi mới nhất theo người nhận) của các phiếu trong kỳ. @return array{retried:int, sent:int, failed:int} */
    public function retryFailed(int $periodId): array
    {
        $out = ['retried' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($this->report($periodId)['rows'] as $row) {
            foreach ($row['notifications'] as $n) {
                if ($n['latest'] && $n['status'] === 'failed') {
                    $out['retried']++;
                    try {
                        $this->mail->retry((int) $n['id'])['status'] === 'sent' ? $out['sent']++ : $out['failed']++;
                    } catch (ValidationException $e) {
                        $out['failed']++;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Màn "Hộp thư theo kỳ" + trạng thái trên trang chi tiết kỳ: mỗi bản tính đủ điều kiện + người nhận hiện tại + thư đã tạo.
     * Trạng thái căn: sent (mọi người nhận đã có thư gửi thành công) | failed (còn thư lỗi mới nhất) | missing (không ai có email) | pending (chưa gửi).
     */
    public function report(int $periodId): array
    {
        $period = $this->periods->find($periodId);
        if ($period === null) {
            throw ValidationException::withMessage('Kỳ không tồn tại.');
        }
        $rows = [];
        $count = ['total' => 0, 'sent' => 0, 'failed' => 0, 'missing' => 0, 'pending' => 0];
        foreach ($this->eligibleCharges($periodId) as $charge) {
            $recipients = $this->notifications->activeRecipients((int) $charge['apartment_id'], date('Y-m-d'));
            $sent = [];
            $latestSeen = [];
            $list = [];
            foreach ($this->notifications->forCharge((int) $charge['id']) as $n) { // mới nhất trước
                $email = (string) $n['recipient_email'];
                $n['latest'] = !isset($latestSeen[$email]);
                $latestSeen[$email] = true;
                if ($n['status'] === 'sent') {
                    $sent[$email] = true;
                }
                $list[] = $n;
            }
            $failedOpen = array_filter($list, static fn (array $n): bool => $n['latest'] && $n['status'] === 'failed' && !isset($sent[(string) $n['recipient_email']]));
            $notSent = array_filter($recipients, static fn (array $r): bool => !isset($sent[strtolower(trim((string) $r['email']))]));
            $state = $recipients === [] && $list === [] ? 'missing' : ($failedOpen !== [] ? 'failed' : ($notSent === [] && $sent !== [] ? 'sent' : ($recipients === [] ? 'sent' : 'pending')));
            $count['total']++;
            $count[$state]++;
            $rows[] = ['charge' => $charge, 'recipients' => $recipients, 'notifications' => $list, 'state' => $state, 'failed_open' => count($failedOpen)];
        }

        return ['period' => $period, 'rows' => $rows, 'count' => $count];
    }

    /**
     * "Đã gửi Gmail": mọi căn gửi được đều đã có thư thành công - không còn căn Chưa gửi / Lỗi (căn Thiếu email không gửi được nên không tính).
     * @param array{total:int, sent:int, failed:int, missing:int, pending:int} $count từ report()
     */
    public static function isComplete(array $count): bool
    {
        return $count['sent'] > 0 && $count['pending'] === 0 && $count['failed'] === 0;
    }

    /** Bản tính trạng thái xác nhận trở lên (billing.lockable_statuses) của kỳ, kèm mã căn / tòa. */
    private function eligibleCharges(int $periodId): array
    {
        $statuses = config('billing.lockable_statuses');

        return array_values(array_filter($this->charges->listByPeriod($periodId), static fn (array $c): bool => in_array($c['status'], $statuses, true)));
    }
}
