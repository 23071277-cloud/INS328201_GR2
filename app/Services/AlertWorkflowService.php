<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\AlertRepository;
use App\Repositories\InspectionCaseRepository;

/**
 * FN-07.06 Review & mức ưu tiên / FN-07.10 Đóng & mở lại - BR-30, BR-31, VAL-07.01.
 * Mọi thao tác: transaction + SELECT … FOR UPDATE + version token (2 người review cùng lúc -> bản sau bị từ chối)
 * + AlertStateMachine (chặn chuyển trạng thái sai ở Service) + alert_status_history + audit.
 */
class AlertWorkflowService extends BaseService
{
    private AlertRepository $alerts;
    private InspectionCaseRepository $cases;

    public function __construct()
    {
        $this->alerts = new AlertRepository();
        $this->cases = new InspectionCaseRepository();
    }

    /** Mới -> Đã review, đặt ưu tiên, ghi chú (FN-07.06). */
    public function review(int $id, string $priority, string $note, string $token): void
    {
        $priority = $this->validPriority($priority);
        $note = $this->optionalNote($note);

        $this->transaction($this->alerts, function () use ($id, $priority, $note, $token): void {
            $alert = $this->lock($id, $token);
            $to = AlertStateMachine::assert('review', $alert['status']);
            $now = date('Y-m-d H:i:s');
            $this->alerts->updateById($id, ['status' => $to, 'priority' => $priority, 'reviewed_by' => $this->actorId(), 'reviewed_at' => $now]);
            $this->alerts->insertHistory($id, $alert['status'], $to, $alert['priority'], $priority, $note, $this->actorId());
            $this->audit('REVIEW_ALERT', 'alert', $id, ['status' => $alert['status'], 'priority' => $alert['priority']],
                ['status' => $to, 'priority' => $priority, 'reviewed_at' => $now, 'note' => $note]);
            if ($priority !== $alert['priority']) {
                $this->audit('CHANGE_ALERT_PRIORITY', 'alert', $id, ['priority' => $alert['priority']], ['priority' => $priority]);
            }
            if (NoUsageConsumptionService::isJ16($alert)) {
                $this->audit('REVIEW_NO_USAGE_ALERT', 'alert', $id, ['status' => $alert['status']], ['status' => $to, 'priority' => $priority, 'note' => $note]);
            }
        });
    }

    /** Đổi mức ưu tiên ở trạng thái chưa đóng (BR-43: BQL được sửa). */
    public function changePriority(int $id, string $priority, string $note, string $token): void
    {
        $priority = $this->validPriority($priority);
        $note = $this->optionalNote($note);

        $this->transaction($this->alerts, function () use ($id, $priority, $note, $token): void {
            $alert = $this->lock($id, $token);
            if (!in_array($alert['status'], AlertStateMachine::PRIORITY_EDITABLE, true)) {
                throw ValidationException::withMessage('Cảnh báo đã đóng. Vui lòng mở lại trước khi thay đổi.'); // VAL-07.01
            }
            if ($priority === $alert['priority']) {
                throw ValidationException::withMessage('Mức ưu tiên không thay đổi.', 'priority');
            }
            $this->alerts->updateById($id, ['priority' => $priority]);
            $this->alerts->insertHistory($id, $alert['status'], $alert['status'], $alert['priority'], $priority, $note, $this->actorId());
            $this->audit('CHANGE_ALERT_PRIORITY', 'alert', $id, ['priority' => $alert['priority']], ['priority' => $priority, 'note' => $note]);
        });
    }

    /** Đã review / Đã xác nhận -> Đã đóng; lý do bắt buộc; không đóng khi còn case chưa có kết quả. */
    public function close(int $id, string $reason, string $token): void
    {
        $reason = $this->requiredReason($reason, 'close_reason');

        $this->transaction($this->alerts, function () use ($id, $reason, $token): void {
            $alert = $this->lock($id, $token);
            $to = AlertStateMachine::assert('close', $alert['status']);
            $j16 = NoUsageConsumptionService::isJ16($alert);
            if ($j16 && !in_array($alert['conclusion'], NoUsageConsumptionService::resolvingConclusions(), true)) {
                throw ValidationException::withMessage('Cảnh báo căn khai báo không sử dụng cần kết luận A, B hoặc C của BQL trước khi đóng (D = còn theo dõi).');
            }
            $open = $this->cases->openCaseOf($id, true);
            if ($open !== null && $open['status'] === 'da_giao') {
                throw ValidationException::withMessage('Còn yêu cầu kiểm tra chưa có kết quả.');
            }
            $now = date('Y-m-d H:i:s');
            $this->alerts->updateById($id, ['status' => $to, 'closed_by' => $this->actorId(), 'closed_at' => $now, 'close_reason' => $reason]);
            $this->alerts->insertHistory($id, $alert['status'], $to, null, null, $reason, $this->actorId());
            $this->audit('CLOSE_ALERT', 'alert', $id, ['status' => $alert['status']], ['status' => $to, 'closed_at' => $now, 'close_reason' => $reason]);
            if ($j16) {
                $this->audit('CLOSE_NO_USAGE_ALERT', 'alert', $id, ['status' => $alert['status']], ['status' => $to, 'conclusion' => $alert['conclusion'], 'close_reason' => $reason]);
            }
        });
    }

    /** Đã đóng -> Đã review (mở lại có lý do; BQL - ASSUMPTION J-24). Cảnh báo kỳ đã khoá vẫn mở lại được. */
    public function reopen(int $id, string $reason, string $token): void
    {
        $reason = $this->requiredReason($reason, 'reopen_reason');

        $this->transaction($this->alerts, function () use ($id, $reason, $token): void {
            $alert = $this->lock($id, $token);
            $to = AlertStateMachine::assert('reopen', $alert['status']);
            $this->alerts->updateById($id, ['status' => $to, 'reopen_reason' => $reason, 'closed_by' => null, 'closed_at' => null, 'close_reason' => null]);
            $this->alerts->insertHistory($id, $alert['status'], $to, null, null, $reason, $this->actorId());
            $this->audit('REOPEN_ALERT', 'alert', $id,
                ['status' => $alert['status'], 'closed_at' => $alert['closed_at'], 'close_reason' => $alert['close_reason']],
                ['status' => $to, 'reopen_reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------

    private function lock(int $id, string $token): array
    {
        $alert = $this->alerts->findForUpdate($id);
        if ($alert === null) {
            throw ValidationException::withMessage('Cảnh báo không tồn tại.');
        }
        if ($token !== AlertService::versionToken($alert)) {
            throw ValidationException::withMessage('Cảnh báo đã được người khác cập nhật sau khi bạn mở trang. Vui lòng tải lại.');
        }

        return $alert;
    }

    private function validPriority(string $priority): string
    {
        if (!array_key_exists($priority, config('alerts.priority'))) {
            throw ValidationException::withMessage('Mức ưu tiên không hợp lệ.', 'priority');
        }

        return $priority;
    }

    private function optionalNote(string $note): ?string
    {
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw ValidationException::withMessage('Ghi chú tối đa 500 ký tự.', 'note');
        }

        return $note === '' ? null : $note;
    }

    private function requiredReason(string $reason, string $field): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessage('Vui lòng nhập lý do.', $field);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessage('Lý do tối đa 500 ký tự.', $field);
        }

        return $reason;
    }
}
