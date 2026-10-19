<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ZaloNotificationRepository;
use Throwable;

/**
 * Gửi phiếu phí qua Zalo THEO KỲ cho các hộ (kỳ đã khoá, bấm tay) + gửi lại tin lỗi - cùng cách làm PeriodBillEmailService.
 * "Thiếu số điện thoại" không lưu DB: tính động từ người nhận hiện có và ghi trong audit tổng hợp.
 */
class PeriodBillZaloService extends BaseService
{
    private BillingPeriodRepository $periods;
    private ApartmentChargeRepository $charges;
    private ZaloNotificationRepository $notifications;
    private ZaloNotificationService $zalo;

    public function __construct(?ZaloNotificationService $zalo = null)
    {
        $this->periods = new BillingPeriodRepository();
        $this->charges = new ApartmentChargeRepository();
        $this->notifications = new ZaloNotificationRepository();
        $this->zalo = $zalo ?? new ZaloNotificationService();
    }

    /** @return array{charges:int, sent:int, failed:int, already:int, missing:string[], not_eligible:array<string,string>} */
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
            set_time_limit(300);
        }
        $sum = ['charges' => 0, 'sent' => 0, 'failed' => 0, 'already' => 0, 'missing' => [], 'not_eligible' => []];
        foreach ($this->eligibleCharges($periodId) as $charge) {
            $code = $charge['building_code'] . ' / ' . $charge['apartment_code'];
            try {
                (new BillNoticeService())->forCharge((int) $charge['id']);
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
                    $res = $this->zalo->sendBillNotice((int) $charge['id'], (string) $r['id']);
                    $res['status'] === 'sent' ? $sum['sent']++ : $sum['failed']++;
                } catch (ValidationException $e) {
                    if (str_contains($e->getMessage(), 'Không gửi trùng')) {
                        $sum['already']++;
                    } elseif (str_contains($e->getMessage(), 'bị lỗi')) {
                        $sum['failed']++;
                    } else {
                        $sum['not_eligible'][$code . ' → ' . ZaloClient::maskPhone((string) $r['phone84'])] = $e->getMessage();
                    }
                } catch (Throwable $e) {
                    error_log('[bill-zalo] kỳ ' . $periodId . ' bản tính ' . $charge['id'] . ': ' . $e->getMessage());
                    $sum['failed']++;
                }
            }
        }
        $this->audit('SEND_PERIOD_BILL_ZALO', 'billing_period', $periodId, null, ['trigger' => $trigger, 'transport' => $this->zalo->driver()] + $sum);

        return $sum;
    }

    /** @return array{retried:int, sent:int, failed:int} */
    public function retryFailed(int $periodId): array
    {
        $out = ['retried' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($this->eligibleCharges($periodId) as $charge) {
            $latestSeen = [];
            foreach ($this->notifications->forCharge((int) $charge['id']) as $n) { // mới nhất trước
                $phone = (string) $n['recipient_phone'];
                $isLatest = !isset($latestSeen[$phone]);
                $latestSeen[$phone] = true;
                if ($isLatest && $n['status'] === 'failed') {
                    $out['retried']++;
                    try {
                        $this->zalo->retry((int) $n['id'])['status'] === 'sent' ? $out['sent']++ : $out['failed']++;
                    } catch (ValidationException $e) {
                        $out['failed']++;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Tóm tắt theo kỳ cho màn "Hộp thư theo kỳ": mỗi căn có phiếu hợp lệ -> sent | failed | missing (không ai có SĐT) | pending.
     * @return array{total:int, sent:int, failed:int, missing:int, pending:int}
     */
    public function counts(int $periodId): array
    {
        $count = ['total' => 0, 'sent' => 0, 'failed' => 0, 'missing' => 0, 'pending' => 0];
        foreach ($this->eligibleCharges($periodId) as $charge) {
            $recipients = $this->notifications->activeRecipients((int) $charge['apartment_id'], date('Y-m-d'));
            $sent = [];
            $latest = [];
            $failedOpen = 0;
            foreach ($this->notifications->forCharge((int) $charge['id']) as $n) {
                $p = (string) $n['recipient_phone'];
                if ($n['status'] === 'sent') {
                    $sent[$p] = true;
                }
                if (!isset($latest[$p])) {
                    $latest[$p] = $n['status'];
                }
            }
            foreach ($latest as $p => $status) {
                if ($status === 'failed' && !isset($sent[$p])) {
                    $failedOpen++;
                }
            }
            $notSent = array_filter($recipients, static fn (array $r): bool => !isset($sent[$r['phone84']]));
            $state = $recipients === [] && $latest === [] ? 'missing' : ($failedOpen > 0 ? 'failed' : ($notSent === [] && $sent !== [] ? 'sent' : 'pending'));
            $count['total']++;
            $count[$state]++;
        }

        return $count;
    }

    private function eligibleCharges(int $periodId): array
    {
        $statuses = config('billing.lockable_statuses');

        return array_values(array_filter($this->charges->listByPeriod($periodId), static fn (array $c): bool => in_array($c['status'], $statuses, true)));
    }
}
