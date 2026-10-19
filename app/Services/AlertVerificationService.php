<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\AlertRepository;
use App\Repositories\InspectionCaseRepository;
use App\Repositories\MeterReadingRepository;

/**
 * FN-07.07 Yêu cầu kiểm tra lại / FN-07.08 Kết quả kiểm tra / FN-07.09 Xác nhận hoặc điều chỉnh dữ liệu.
 * Core tối thiểu (K-03, J-05): case + kết quả ngắn; lịch, ảnh, hạn xử lý thuộc FN-10.01 (không làm).
 * Điều chỉnh chỉ số gọi FN-04 MeterReadingService::update trong CÙNG transaction (khoá cảnh báo -> kỳ -> chỉ số):
 * giữ validate VAL-04.x, VAL-04.04 kỳ khoá, audit, BR-41 (về Nháp) - và FN-05 tự đánh dấu bản tính CẦN TÍNH LẠI.
 */
class AlertVerificationService extends BaseService
{
    private AlertRepository $alerts;
    private InspectionCaseRepository $cases;

    public function __construct()
    {
        $this->alerts = new AlertRepository();
        $this->cases = new InspectionCaseRepository();
    }

    /** BQL: Đã review -> Cần kiểm tra lại, tạo case Đã giao cho 1 Kỹ thuật (BR-32). */
    public function request(int $alertId, string $content, string $assignee, string $token): int
    {
        $errors = [];
        $content = trim($content);
        if ($content === '') {
            $errors['content'][] = 'Vui lòng nhập nội dung cần kiểm tra.';
        } elseif (mb_strlen($content) > 500) {
            $errors['content'][] = 'Nội dung tối đa 500 ký tự.';
        }
        if (!ctype_digit($assignee) || !$this->cases->isActiveTechnician((int) $assignee)) {
            $errors['assigned_to'][] = 'Vui lòng chọn Kỹ thuật đang hoạt động.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->transaction($this->alerts, function () use ($alertId, $content, $assignee, $token): int {
            $alert = $this->lock($alertId, $token);
            $to = AlertStateMachine::assert('request_verification', $alert['status']);
            if ($this->cases->openCaseOf($alertId, true) !== null) {
                throw ValidationException::withMessage('Cảnh báo đã có yêu cầu kiểm tra đang mở.');
            }
            $caseId = $this->cases->insertRow(['alert_id' => $alertId, 'content' => $content, 'assigned_to' => (int) $assignee, 'status' => 'da_giao', 'created_by' => $this->actorId()]);
            $this->alerts->updateById($alertId, ['status' => $to]);
            $this->alerts->insertHistory($alertId, $alert['status'], $to, null, null, 'Yêu cầu kiểm tra #' . $caseId . ': ' . $content, $this->actorId());
            $this->audit('REQUEST_ALERT_VERIFICATION', 'alert', $alertId, ['status' => $alert['status']],
                ['status' => $to, 'case_id' => $caseId, 'assigned_to' => (int) $assignee, 'content' => $content]);
            if (NoUsageConsumptionService::isJ16($alert)) {
                $this->audit('REQUEST_TECH_VERIFICATION', 'alert', $alertId, null, ['case_id' => $caseId, 'assigned_to' => (int) $assignee, 'reason' => config('j16.tech_reason')]);
            }

            return $caseId;
        });
    }

    /** Kỹ thuật được giao: case Đã giao -> Đã có kết quả (1 lần). Không đổi trạng thái cảnh báo (BQL kết luận). */
    public function submitResult(int $alertId, string $conclusion, string $recheckedRaw, string $note): void
    {
        $errors = [];
        // J-16: kết quả kỹ thuật riêng (METER_OK … OTHER) - Kỹ thuật không kết luận trạng thái căn
        $alertRow = $this->alerts->find($alertId);
        $options = $alertRow !== null && NoUsageConsumptionService::isJ16($alertRow) ? config('j16.tech_results') : config('alerts.conclusion');
        if (!array_key_exists($conclusion, $options)) {
            $errors['result_conclusion'][] = 'Vui lòng chọn kết luận kiểm tra.';
        }
        $rechecked = null;
        if (trim($recheckedRaw) !== '') {
            $rechecked = Decimal::toCents(trim($recheckedRaw));
            if ($rechecked === null) {
                $errors['rechecked_value'][] = 'Chỉ số đọc lại phải là số ≥ 0, tối đa 2 chữ số thập phân.';
            }
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            $errors['note'][] = 'Ghi chú tối đa 500 ký tự.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->transaction($this->alerts, function () use ($alertId, $conclusion, $rechecked, $note, $options): void {
            $alert = $this->alerts->findForUpdate($alertId);
            if ($alert === null) {
                throw ValidationException::withMessage('Cảnh báo không tồn tại.');
            }
            $case = $this->cases->openCaseOf($alertId, true);
            if ($case === null || $case['status'] !== 'da_giao') {
                throw ValidationException::withMessage('Không có yêu cầu kiểm tra đang chờ kết quả (có thể đã được cập nhật).');
            }
            if ((int) $case['assigned_to'] !== (int) $this->actorId()) {
                throw ValidationException::withMessage('Yêu cầu kiểm tra này không giao cho bạn.');
            }
            $changes = ['status' => 'da_co_ket_qua', 'result_conclusion' => $conclusion,
                'rechecked_value' => $rechecked === null ? null : Decimal::fromCents($rechecked), 'note' => $note === '' ? null : $note];
            $this->cases->updateById((int) $case['id'], $changes);
            $this->alerts->insertHistory($alertId, $alert['status'], $alert['status'], null, null,
                mb_substr('Kết quả kiểm tra #' . $case['id'] . ': ' . $options[$conclusion] . ($note !== '' ? ' — ' . $note : ''), 0, 500), $this->actorId());
            $this->audit('UPDATE_ALERT_VERIFICATION', 'alert', $alertId, ['case_id' => (int) $case['id'], 'case_status' => 'da_giao'], ['case_id' => (int) $case['id']] + $changes);
        });
    }

    /**
     * BQL (07.09): decision = confirm (dữ liệu đúng) | adjust (sửa chỉ số qua FN-04.07).
     * Cần kiểm tra lại -> Đã xác nhận; case -> Đã đóng.
     * @param array{current_reading?:string, reading_token?:string} $adjust
     */
    public function confirm(int $alertId, string $decision, string $note, string $token, array $adjust = []): void
    {
        if (!in_array($decision, ['confirm', 'adjust'], true)) {
            throw ValidationException::withMessage('Vui lòng chọn Xác nhận dữ liệu đúng hoặc Điều chỉnh.', 'decision');
        }
        $note = trim($note);
        if ($decision === 'adjust' && $note === '') {
            throw ValidationException::withMessage('Vui lòng nhập lý do điều chỉnh.', 'note');
        }
        if (mb_strlen($note) > 500) {
            throw ValidationException::withMessage('Ghi chú tối đa 500 ký tự.', 'note');
        }

        $this->transaction($this->alerts, function () use ($alertId, $decision, $note, $token, $adjust): void {
            $alert = $this->lock($alertId, $token);
            if (NoUsageConsumptionService::isJ16($alert)) {
                throw ValidationException::withMessage('Cảnh báo căn khai báo không sử dụng dùng "Kết luận BQL" (A/B/C/D).');
            }
            $to = AlertStateMachine::assert('confirm', $alert['status']);
            $case = $this->cases->openCaseOf($alertId, true);
            if ($case === null || $case['status'] !== 'da_co_ket_qua') {
                throw ValidationException::withMessage('Còn yêu cầu kiểm tra chưa có kết quả.');
            }

            $adjusted = null;
            if ($decision === 'adjust') {
                if ($alert['meter_id'] === null) {
                    throw ValidationException::withMessage('Cảnh báo không gắn công tơ — không có chỉ số để điều chỉnh.');
                }
                $reading = (new MeterReadingRepository())->findByMeterPeriod((int) $alert['meter_id'], (int) $alert['billing_period_id']);
                if ($reading === null) {
                    throw ValidationException::withMessage('Không có chỉ số của công tơ trong kỳ để điều chỉnh.');
                }
                $newCents = Decimal::toCents(trim((string) ($adjust['current_reading'] ?? '')));
                if ($newCents !== null && $newCents === Decimal::toCents((string) $reading['current_reading'])) {
                    throw ValidationException::withMessage('Chỉ số mới giống chỉ số hiện tại — chọn "Xác nhận dữ liệu đúng" nếu không cần điều chỉnh.', 'current_reading');
                }
                // FN-04.07: validate, kỳ khoá (VAL-04.04), BR-41 về Nháp, audit UPDATE_METER_READING
                $changed = (new MeterReadingService())->update((int) $reading['id'], [
                    'current_reading' => (string) ($adjust['current_reading'] ?? ''),
                    'note' => 'Điều chỉnh sau kiểm tra (cảnh báo #' . $alertId . '): ' . $note,
                    'version_token' => (string) ($adjust['reading_token'] ?? ''),
                ]);
                if (!$changed) {
                    throw ValidationException::withMessage('Chỉ số mới giống chỉ số hiện tại — chọn "Xác nhận dữ liệu đúng" nếu không cần điều chỉnh.', 'current_reading');
                }
                $adjusted = ['reading_id' => (int) $reading['id'], 'old_current' => $reading['current_reading'], 'new_current' => (string) ($adjust['current_reading'] ?? '')];
            }

            $this->cases->updateById((int) $case['id'], ['status' => 'da_dong']);
            $this->alerts->updateById($alertId, ['status' => $to]);
            $text = $decision === 'adjust' ? 'Đã điều chỉnh chỉ số (#' . $adjusted['reading_id'] . ' ' . $adjusted['old_current'] . ' → ' . $adjusted['new_current'] . '): ' . $note
                : 'Xác nhận dữ liệu đúng' . ($note !== '' ? ': ' . $note : '');
            $this->alerts->insertHistory($alertId, $alert['status'], $to, null, null, mb_substr($text, 0, 500), $this->actorId());
            $this->audit('CONFIRM_ALERT_DATA', 'alert', $alertId, ['status' => $alert['status'], 'case_status' => 'da_co_ket_qua'],
                ['status' => $to, 'decision' => $decision, 'note' => $note, 'adjusted' => $adjusted, 'case_id' => (int) $case['id']]);
        });
    }

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
}
