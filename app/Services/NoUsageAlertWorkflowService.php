<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\AlertContactLogRepository;
use App\Repositories\AlertRepository;
use App\Repositories\ApartmentRepository;
use App\Repositories\InspectionCaseRepository;
use App\Repositories\MeterReadingRepository;
use DateTime;

/**
 * [BA Proposal / Assumption J-16] thao tác BQL trên cảnh báo "Phát sinh tiêu thụ tại căn khai báo không sử dụng".
 * Tái dùng FN-07: trạng thái alerts (AlertStateMachine), review / yêu cầu kiểm tra / kết quả kỹ thuật / đóng / mở lại.
 * Thêm: ghi nhận gọi điện + phản hồi cư dân (alert_contact_logs), kết luận A/B/C/D, CTA theo kết luận
 * (A: cập nhật trạng thái căn - BQL xác nhận rõ; B: điều chỉnh chỉ số qua FN-04.07). Không tự kết luận nguyên nhân.
 * Mọi thao tác: transaction + FOR UPDATE + version token (trừ ghi nhận liên hệ - chỉ thêm dòng) + lịch sử + audit.
 */
class NoUsageAlertWorkflowService extends BaseService
{
    private AlertRepository $alerts;
    private AlertContactLogRepository $contacts;
    private InspectionCaseRepository $cases;

    public function __construct()
    {
        $this->alerts = new AlertRepository();
        $this->contacts = new AlertContactLogRepository();
        $this->cases = new InspectionCaseRepository();
    }

    /** "Đã gọi chủ hộ" (contact_type = phone) hoặc phản hồi cư dân (resident_response). Không gọi điện tự động. */
    public function recordContact(int $alertId, string $type, string $time, string $result, string $note): int
    {
        $options = $type === 'phone' ? config('j16.phone_results') : ($type === 'resident_response' ? config('j16.resident_responses') : null);
        $errors = [];
        if ($options === null) {
            throw ValidationException::withMessage('Loại ghi nhận không hợp lệ.');
        }
        if (!array_key_exists($result, $options)) {
            $errors['result'][] = $type === 'phone' ? 'Vui lòng chọn kết quả cuộc gọi.' : 'Vui lòng chọn phản hồi của cư dân.';
        }
        $at = DateTime::createFromFormat('!Y-m-d\TH:i', $time);
        if ($at === false || $at->format('Y-m-d\TH:i') !== $time) {
            $errors['contact_time'][] = 'Thời điểm liên hệ không hợp lệ.';
        } elseif ($at->getTimestamp() > time() + 300) {
            $errors['contact_time'][] = 'Thời điểm liên hệ không được ở tương lai.';
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            $errors['contact_note'][] = 'Ghi chú tối đa 500 ký tự.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->transaction($this->alerts, function () use ($alertId, $type, $at, $result, $note, $options): int {
            $alert = $this->lockJ16($alertId);
            if ($alert['status'] === 'da_dong') {
                throw ValidationException::withMessage('Cảnh báo đã đóng. Vui lòng mở lại trước khi thay đổi.');
            }
            $row = ['alert_id' => $alertId, 'contact_type' => $type, 'contact_time' => $at->format('Y-m-d H:i:s'), 'result' => $result,
                'note' => $note === '' ? null : $note, 'created_by' => $this->actorId()];
            $id = $this->contacts->insertRow($row);
            $label = ($type === 'phone' ? 'Đã gọi chủ hộ: ' : 'Phản hồi cư dân: ') . $options[$result];
            $this->alerts->insertHistory($alertId, $alert['status'], $alert['status'], null, null, mb_substr($label . ($note !== '' ? ' — ' . $note : ''), 0, 500), $this->actorId());
            $this->audit($type === 'phone' ? 'RECORD_PHONE_CONTACT' : 'RECORD_RESIDENT_RESPONSE', 'alert', $alertId, null, ['contact_log_id' => $id] + $row);

            return $id;
        });
    }

    /**
     * Kết luận BQL. A/B/C: gỡ chặn xác nhận bản tính, cảnh báo -> Đã xác nhận (case đã có kết quả -> Đã đóng).
     * D: KHÔNG gỡ chặn, giữ trạng thái + ngày theo dõi (NEEDS_FOLLOW_UP). Đổi kết luận được khi cảnh báo chưa đóng.
     */
    public function conclude(int $alertId, string $conclusion, string $note, string $followUp, string $token): void
    {
        $options = config('j16.conclusions');
        $errors = [];
        if (!array_key_exists($conclusion, $options)) {
            $errors['conclusion'][] = 'Vui lòng chọn kết luận.';
        }
        $note = trim($note);
        if ($note === '') {
            $errors['conclusion_note'][] = 'Vui lòng ghi rõ căn cứ kết luận.';
        } elseif (mb_strlen($note) > 500) {
            $errors['conclusion_note'][] = 'Ghi chú tối đa 500 ký tự.';
        }
        $followDate = null;
        if ($conclusion === 'undetermined') {
            $d = DateTime::createFromFormat('!Y-m-d', $followUp);
            if ($d === false || $d->format('Y-m-d') !== $followUp) {
                $errors['follow_up_date'][] = 'Vui lòng chọn ngày theo dõi lại.';
            } elseif ($followUp < date('Y-m-d')) {
                $errors['follow_up_date'][] = 'Ngày theo dõi lại không được ở quá khứ.';
            } else {
                $followDate = $followUp;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->transaction($this->alerts, function () use ($alertId, $conclusion, $note, $followDate, $token, $options): void {
            $alert = $this->lockJ16($alertId, $token);
            if ($alert['status'] === 'moi') {
                throw ValidationException::withMessage('Vui lòng review cảnh báo trước khi kết luận.');
            }
            $resolves = $options[$conclusion]['resolves'];
            $to = $alert['status'];
            $case = $this->cases->openCaseOf($alertId, true);
            if ($resolves) {
                $to = AlertStateMachine::assert('conclude', $alert['status']); // Đã đóng -> lỗi "mở lại trước"
                if ($case !== null && $case['status'] === 'da_giao') {
                    throw ValidationException::withMessage('Còn yêu cầu kiểm tra của Kỹ thuật chưa có kết quả — chờ kết quả hoặc chọn D (theo dõi).');
                }
                if ($case !== null) {
                    $this->cases->updateById((int) $case['id'], ['status' => 'da_dong']);
                }
            } elseif ($alert['status'] === 'da_dong') {
                throw ValidationException::withMessage('Cảnh báo đã đóng. Vui lòng mở lại trước khi thay đổi.');
            }
            $now = date('Y-m-d H:i:s');
            $changes = ['status' => $to, 'conclusion' => $conclusion, 'conclusion_note' => $note, 'concluded_by' => $this->actorId(),
                'concluded_at' => $now, 'follow_up_date' => $followDate];
            $this->alerts->updateById($alertId, $changes);
            $this->alerts->insertHistory($alertId, $alert['status'], $to, null, null, mb_substr('Kết luận BQL: ' . $options[$conclusion]['label']
                . ($followDate !== null ? ' (theo dõi lại ' . format_date($followDate) . ')' : '') . ' — ' . $note, 0, 500), $this->actorId());
            $this->audit('CONCLUDE_NO_USAGE_ALERT', 'alert', $alertId,
                ['status' => $alert['status'], 'conclusion' => $alert['conclusion'], 'follow_up_date' => $alert['follow_up_date']],
                $changes + ['resolves_billing_gate' => $resolves, 'closed_case_id' => $resolves && $case !== null ? (int) $case['id'] : null]);
        });
    }

    /**
     * CTA kết luận A: BQL xác nhận rõ đổi khai báo căn "Không phát sinh điện nước" -> "Có ở / có phát sinh".
     * Bản tính phí của căn tự thành CẦN TÍNH LẠI (FN-05 so snapshot occupancy_status) - Kế toán tính lại.
     */
    public function updateApartmentStatus(int $alertId, string $confirmed, string $token): void
    {
        if ($confirmed !== '1') {
            throw ValidationException::withMessage('Vui lòng tích xác nhận cập nhật trạng thái căn hộ.', 'confirm_apartment');
        }
        $this->transaction($this->alerts, function () use ($alertId, $token): void {
            $alert = $this->lockJ16($alertId, $token);
            if ($alert['conclusion'] !== 'apartment_status_incorrect') {
                throw ValidationException::withMessage('Chỉ cập nhật trạng thái căn khi kết luận là "A. Trạng thái căn hộ khai báo sai".');
            }
            if ($alert['status'] === 'da_dong') {
                throw ValidationException::withMessage('Cảnh báo đã đóng. Vui lòng mở lại trước khi thay đổi.');
            }
            $apartments = new ApartmentRepository();
            $apartment = $apartments->findForUpdate((int) $alert['apartment_id']);
            $declared = (string) config('j16.declared_status');
            if ($apartment === null || $apartment['occupancy_status'] !== $declared) {
                throw ValidationException::withMessage('Căn hộ không còn khai báo "Không phát sinh điện nước" — không cần cập nhật.');
            }
            $apartments->updateById((int) $apartment['id'], ['occupancy_status' => 'co_o']);
            $this->alerts->insertHistory($alertId, $alert['status'], $alert['status'], null, null,
                'Cập nhật trạng thái căn ' . $apartment['code'] . ': ' . label_source('occupancy_status', $declared) . ' → ' . label_source('occupancy_status', 'co_o')
                . ' (BQL xác nhận). Bản tính phí của căn cần tính lại nếu đã có.', $this->actorId());
            $this->audit('UPDATE_APARTMENT_FROM_ALERT', 'apartment', (int) $apartment['id'],
                ['occupancy_status' => $declared], ['occupancy_status' => 'co_o', 'alert_id' => $alertId]);
        });
    }

    /**
     * CTA kết luận B: điều chỉnh CSC 1 chỉ số trong snapshot qua FN-04.07 (MeterReadingService::update - validate, kỳ khoá,
     * BR-41 về Nháp, audit; FN-05 tự đánh dấu bản tính CẦN TÍNH LẠI). Duyệt lại chỉ số -> J-16 đánh giá lại.
     */
    public function adjustReading(int $alertId, string $readingId, string $currentReading, string $note, string $readingToken, string $token): void
    {
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessage('Vui lòng nhập lý do điều chỉnh.', 'adjust_note');
        }
        if (mb_strlen($note) > 400) {
            throw ValidationException::withMessage('Lý do tối đa 400 ký tự.', 'adjust_note');
        }
        $this->transaction($this->alerts, function () use ($alertId, $readingId, $currentReading, $note, $readingToken, $token): void {
            $alert = $this->lockJ16($alertId, $token);
            if ($alert['conclusion'] !== 'reading_incorrect') {
                throw ValidationException::withMessage('Chỉ điều chỉnh chỉ số khi kết luận là "B. Chỉ số công tơ ghi sai".');
            }
            if ($alert['status'] === 'da_dong') {
                throw ValidationException::withMessage('Cảnh báo đã đóng. Vui lòng mở lại trước khi thay đổi.');
            }
            $snapshot = json_decode((string) $alert['snapshot_json'], true) ?: [];
            $ids = array_map(static fn (array $m): int => (int) $m['reading_id'], $snapshot['meters'] ?? []);
            if (!ctype_digit($readingId) || !in_array((int) $readingId, $ids, true)) {
                throw ValidationException::withMessage('Chỉ số không thuộc cảnh báo này.', 'reading_id');
            }
            $reading = (new MeterReadingRepository())->find((int) $readingId);
            $changed = (new MeterReadingService())->update((int) $readingId, [
                'current_reading' => $currentReading,
                'note' => 'Điều chỉnh từ cảnh báo căn khai báo không sử dụng #' . $alertId . ': ' . $note,
                'version_token' => $readingToken,
            ]);
            if (!$changed) {
                throw ValidationException::withMessage('Chỉ số mới giống chỉ số hiện tại.', 'current_reading');
            }
            $this->alerts->insertHistory($alertId, $alert['status'], $alert['status'], null, null, mb_substr('Điều chỉnh chỉ số #' . $readingId . ': CSC '
                . format_decimal($reading['current_reading']) . ' → ' . $currentReading . ' (về Nháp, chờ BQL duyệt lại; hệ thống đánh giá lại khi duyệt) — ' . $note, 0, 500), $this->actorId());
            $this->audit('ADJUST_READING_FROM_ALERT', 'alert', $alertId, ['reading_id' => (int) $readingId, 'current_reading' => $reading['current_reading']],
                ['reading_id' => (int) $readingId, 'current_reading' => $currentReading, 'note' => $note]);
        });
    }

    // ------------------------------------------------------------------

    private function lockJ16(int $id, ?string $token = null): array
    {
        $alert = $this->alerts->findForUpdate($id);
        if ($alert === null || !NoUsageConsumptionService::isJ16($alert)) {
            throw ValidationException::withMessage('Cảnh báo không tồn tại hoặc không phải loại căn khai báo không sử dụng.');
        }
        if ($token !== null && $token !== AlertService::versionToken($alert)) {
            throw ValidationException::withMessage('Cảnh báo đã được người khác cập nhật sau khi bạn mở trang. Vui lòng tải lại.');
        }

        return $alert;
    }
}
