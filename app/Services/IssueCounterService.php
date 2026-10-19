<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\IssueCounterRepository;
use Throwable;

/**
 * Badge "việc cần xử lý" trên sidebar - theo QUYỀN của người dùng (không lộ số của chức năng không được xử lý).
 * Chỉ đếm việc chưa hoàn tất mà chính role đó làm được:
 *   alerts  - BQL (alerts.review): cảnh báo Mới / đã có kết quả KT / J-16 chưa kết luận; Kỹ thuật (alerts.update_verification): case được giao chờ kết quả
 *   periods - BQL (meter_readings.approve): chỉ số Nháp chờ duyệt; Kỹ thuật (meter_readings.create): công tơ chưa ghi ở kỳ đang mở
 *   billing - Kế toán (billing.confirm): bản tính Đã tính chưa bị J-16 chặn + bản Bị trả lại
 *   emails  - email_notifications.send: email gửi lỗi cần gửi lại
 * "Chỉ số bị trả lại" không đếm: chức năng Trả lại chỉ số chưa có (BLOCKED B6/J-13). Kết quả nhớ trong request.
 */
class IssueCounterService
{
    /** @var array<string,int>|null */
    private static ?array $cache = null;

    /** @return array<string,int> khoá menu => số việc (chỉ khoá > 0) */
    public static function forCurrentUser(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $user = auth_user();
        if ($user === null) {
            return [];
        }
        $repo = new IssueCounterRepository();
        $j16 = NoUsageConsumptionService::type();
        $resolving = NoUsageConsumptionService::resolvingConclusions();
        $counts = ['alerts' => 0, 'periods' => 0, 'billing' => 0, 'emails' => 0];
        try {
            if (can('alerts.review')) {
                $counts['alerts'] += $repo->alertsForManager($j16, $resolving);
            }
            if (can('alerts.update_verification')) {
                $counts['alerts'] += $repo->casesAssignedTo((int) $user['id']);
            }
            if (can('meter_readings.approve')) {
                $counts['periods'] += $repo->draftReadings();
            }
            if (can('meter_readings.create')) {
                $counts['periods'] += $repo->unreadMeters();
            }
            if (can('billing.confirm')) {
                $counts['billing'] += $repo->chargesToProcess($j16, $resolving);
            }
            if (can('email_notifications.send')) {
                $counts['emails'] += $repo->failedEmails();
            }
        } catch (Throwable $e) {
            error_log('[issue-counter] ' . $e->getMessage()); // badge là phụ: lỗi đếm không làm hỏng trang
            $counts = [];
        }

        return self::$cache = array_filter($counts, static fn (int $n): bool => $n > 0);
    }

    /** Test / sau thao tác trong cùng request. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
