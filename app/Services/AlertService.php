<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\AlertRepository;
use App\Repositories\AlertRuleRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ConsumptionAnalyticsRepository;
use App\Repositories\InspectionCaseRepository;
use App\Repositories\MeterReadingRepository;

/**
 * FN-07.03 Cảnh báo thủ công (Kỹ thuật) / FN-07.04 Danh sách / FN-07.05 Chi tiết.
 * Kỹ thuật chỉ thấy cảnh báo mình tạo hoặc có case giao cho mình ([ĐX] K-18, S12 - NEED BA J-24).
 */
class AlertService extends BaseService
{
    private AlertRepository $alerts;
    private InspectionCaseRepository $cases;
    private ConsumptionAnalyticsRepository $analytics;
    private BillingPeriodRepository $periods;

    public function __construct()
    {
        $this->alerts = new AlertRepository();
        $this->cases = new InspectionCaseRepository();
        $this->analytics = new ConsumptionAnalyticsRepository();
        $this->periods = new BillingPeriodRepository();
    }

    public function list(array $filters, array $user): array
    {
        return array_map(static fn (array $a): array => $a + ['version_token' => self::versionToken($a)],
            $this->alerts->search($filters, $user['role'] === 'ky_thuat' ? (int) $user['id'] : null));
    }

    public function canView(int $alertId, array $user): bool
    {
        return $user['role'] !== 'ky_thuat' || $this->alerts->visibleToTechnician($alertId, (int) $user['id']);
    }

    /** FN-07.05: cảnh báo + "vì sao" (snapshot) + so sánh FN-06 hiện tại + lịch sử + case + dữ liệu điều chỉnh. */
    public function detail(int $id): ?array
    {
        $alert = $this->alerts->findDetailed($id);
        if ($alert === null) {
            return null;
        }
        if (NoUsageConsumptionService::isJ16($alert)) {
            return $this->j16Detail($alert);
        }
        $snapshotLog = (new AuditLogRepository())->latestForEntity('alert', (string) $id, ['CREATE_ALERT_AUTO']);
        $snapshot = $snapshotLog !== null ? json_decode((string) $snapshotLog['new_value'], true) : null;
        $scopeType = $alert['meter_id'] !== null ? 'meter' : 'apartment';
        $scopeId = (int) ($alert['meter_id'] ?? $alert['apartment_id']);
        $reading = $alert['meter_id'] !== null ? (new MeterReadingRepository())->findByMeterPeriod((int) $alert['meter_id'], (int) $alert['billing_period_id']) : null;
        if ($reading !== null) {
            $reading['version_token'] = MeterReadingService::versionToken($reading);
        }

        return [
            'alert' => $alert + ['version_token' => self::versionToken($alert)],
            'rule_snapshot' => $snapshot['rule_snapshot'] ?? null,
            'comparison_now' => (new ConsumptionComparisonService())->compare($scopeType, $scopeId, $alert['utility_type'], (int) $alert['billing_period_id']),
            'history' => $this->alerts->history($id),
            'cases' => $this->cases->casesOf($id),
            'open_case' => $this->cases->openCaseOf($id),
            'reading' => $reading,
            'technicians' => $this->cases->technicians(),
        ];
    }

    /**
     * [BA Proposal J-16] chi tiết: snapshot phát hiện + chỉ số hiện tại của các công tơ liên quan + liên hệ cư dân + email + case + lịch sử.
     * Không có "so sánh FN-06" (cảnh báo cấp căn, không theo ngưỡng).
     */
    private function j16Detail(array $alert): array
    {
        $id = (int) $alert['id'];
        $snapshot = json_decode((string) $alert['snapshot_json'], true) ?: [];
        $readingsRepo = new MeterReadingRepository();
        $readings = [];
        foreach ($snapshot['meters'] ?? [] as $m) {
            $r = $readingsRepo->findDetailed((int) $m['reading_id']);
            if ($r !== null) {
                $r['version_token'] = MeterReadingService::versionToken($r);
                $readings[(int) $m['reading_id']] = $r;
            }
        }
        $emails = new \App\Repositories\EmailNotificationRepository();

        return [
            'alert' => $alert + ['version_token' => self::versionToken($alert)],
            'j16' => true,
            'snapshot' => $snapshot,
            'readings' => $readings,
            'contacts' => (new \App\Repositories\AlertContactLogRepository())->forAlert($id),
            'recipients' => $emails->activeRecipients((int) $alert['apartment_id'], date('Y-m-d')),
            'emails' => $emails->forAlert($id),
            'rule_snapshot' => null,
            'comparison_now' => null,
            'history' => $this->alerts->history($id),
            'cases' => $this->cases->casesOf($id),
            'open_case' => $this->cases->openCaseOf($id),
            'reading' => null,
            'technicians' => $this->cases->technicians(),
        ];
    }

    /** Dữ liệu cho form tạo tay. */
    public function formOptions(): array
    {
        return [
            'apartments' => $this->analytics->scopeEntities('apartment', true),
            'meters' => $this->analytics->scopeEntities('meter', true),
            'periods' => $this->periods->options(),
            'zones' => $this->analytics->scopeEntities('zone'),
            'rules' => (new AlertRuleRepository())->all(),
        ];
    }

    /** FN-07.03 - @return array{id:int, open_existing:int} */
    public function createManual(array $input): array
    {
        $data = $this->validateManual($input);

        return $this->transaction($this->alerts, function () use ($data): array {
            $existing = $this->alerts->openFor($data['apartment_id'], $data['meter_id'], $data['billing_period_id']);
            $row = $data + ['alert_rule_id' => null, 'source' => 'thu_cong', 'status' => 'moi', 'created_by' => $this->actorId()];
            $id = $this->alerts->insertRow($row);
            $this->alerts->insertHistory($id, null, 'moi', null, $row['priority'], 'Kỹ thuật tạo thủ công', $this->actorId());
            $this->audit('CREATE_ALERT_MANUAL', 'alert', $id, null, $row);

            return ['id' => $id, 'open_existing' => $existing];
        });
    }

    public static function versionToken(array $alert): string
    {
        return sha1(implode('|', [$alert['id'], $alert['status'], $alert['priority'], $alert['reviewed_at'] ?? '', $alert['closed_at'] ?? '', $alert['reopen_reason'] ?? '', $alert['updated_at'],
            $alert['conclusion'] ?? '', $alert['concluded_at'] ?? '', $alert['follow_up_date'] ?? '', isset($alert['snapshot_json']) ? sha1((string) $alert['snapshot_json']) : '']));
    }

    // ------------------------------------------------------------------

    private function validateManual(array $input): array
    {
        $errors = [];
        $aptRaw = (string) ($input['apartment_id'] ?? '');
        $meterRaw = (string) ($input['meter_id'] ?? '');
        $periodRaw = (string) ($input['billing_period_id'] ?? '');
        $utility = (string) ($input['utility_type'] ?? '');
        $description = trim((string) ($input['description'] ?? ''));
        $priority = (string) ($input['priority'] ?? 'trung_binh');

        $apartment = ctype_digit($aptRaw) ? $this->analytics->scopeEntity('apartment', (int) $aptRaw) : null;
        if ($apartment === null) {
            $errors['apartment_id'][] = 'Căn hộ không tồn tại.';
        } elseif ($apartment['status'] !== 'hoat_dong') {
            $errors['apartment_id'][] = 'Căn hộ đã ngừng hoạt động.';
        }
        if (!array_key_exists($utility, config('master_data.utility_type'))) {
            $errors['utility_type'][] = 'Loại tiện ích không hợp lệ.';
        }
        $meterId = null;
        if ($meterRaw !== '') {
            $meter = ctype_digit($meterRaw) ? (new MeterReadingService())->meterWithApartment((int) $meterRaw) : null;
            if ($meter === null) {
                $errors['meter_id'][] = 'Công tơ không tồn tại.';
            } elseif ($apartment !== null && (int) $meter['apartment_id'] !== (int) $apartment['id']) {
                $errors['meter_id'][] = 'Công tơ không thuộc căn hộ đã chọn.';
            } elseif ($meter['status'] !== 'hoat_dong') {
                $errors['meter_id'][] = 'Công tơ đã ngừng hoạt động.';
            } elseif ($utility !== '' && $meter['utility_type'] !== $utility) {
                $errors['meter_id'][] = 'Công tơ không cùng loại tiện ích.';
            } else {
                $meterId = (int) $meter['id'];
            }
        }
        if (!ctype_digit($periodRaw) || $this->periods->find((int) $periodRaw) === null) {
            $errors['billing_period_id'][] = 'Kỳ không hợp lệ.';
        }
        if ($description === '') {
            $errors['description'][] = 'Vui lòng mô tả hiện tượng bất thường.'; // [ĐX] FN-07.03
        } elseif (mb_strlen($description) > 500) {
            $errors['description'][] = 'Mô tả tối đa 500 ký tự.';
        }
        if (!array_key_exists($priority, config('alerts.priority'))) {
            $errors['priority'][] = 'Mức ưu tiên không hợp lệ.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['apartment_id' => (int) $aptRaw, 'meter_id' => $meterId, 'billing_period_id' => (int) $periodRaw, 'utility_type' => $utility,
            'description' => $description, 'priority' => $priority];
    }
}
