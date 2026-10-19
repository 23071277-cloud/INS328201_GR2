<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLogger;
use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\ApartmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\FixedFeeRepository;
use App\Repositories\TariffRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * FN-05.02 - 05.04 Tính phí căn hộ-kỳ (điều phối; công thức nằm ở TariffCalculator / FixedFeeCalculator /
 * ChargeAggregationService, làm tròn ở MoneyCalculator).
 *
 * Một căn = MỘT transaction (Q10): khoá kỳ (SELECT … FOR UPDATE - cùng khoá FN-04 dùng khi nhập/sửa/duyệt/
 * import, nên không có "chỉ số mới + tiền cũ" xen giữa) -> khoá bản tính -> đọc chỉ số đã xác nhận ->
 * chọn biểu giá/phí hiệu lực -> tính -> ghi apartment_charges + charge_tier_details + audit snapshot -> COMMIT.
 * Lỗi ở bất kỳ bước nào -> ROLLBACK cả căn.
 *
 * Idempotent (Q11): kết quả giống hệt bản đang lưu -> không ghi; khác -> cập nhật tại chỗ, về Đã tính.
 * Tính nhất quán (Q12, P19): bản tính chỉ "hiện hành" khi tính thử lại cho đúng các cột, các dòng bậc và
 * phiên bản biểu giá/phí trong snapshot; ngược lại CẦN TÍNH LẠI (chặn xác nhận/duyệt/khoá).
 */
class BillingCalculationService extends BaseService
{
    public const SNAPSHOT_ACTIONS = ['CALCULATE_APARTMENT_CHARGE', 'RECALCULATE_APARTMENT_CHARGE'];

    private BillingPeriodRepository $periods;
    private ApartmentChargeRepository $charges;
    private ApartmentRepository $apartments;
    private TariffRepository $tariffs;
    private FixedFeeRepository $fees;
    private AuditLogRepository $audits;
    private ConsumptionService $consumption;

    public function __construct()
    {
        $this->periods = new BillingPeriodRepository();
        $this->charges = new ApartmentChargeRepository();
        $this->apartments = new ApartmentRepository();
        $this->tariffs = new TariffRepository();
        $this->fees = new FixedFeeRepository();
        $this->audits = new AuditLogRepository();
        $this->consumption = new ConsumptionService($this->charges);
    }

    // ------------------------------------------------------------------
    // Tính (không ghi)
    // ------------------------------------------------------------------

    /**
     * Tính bản tính của 1 căn từ dữ liệu HIỆN TẠI trong DB (không ghi gì).
     * @return array{ok:bool, errors:string[], warnings:string[], meters:array, fees:array, not_calculated:array,
     *               columns:array<string,int>, totals:array<string,int>, snapshot:array}
     */
    public function compute(array $period, array $apartment, int $oldDebtCents): array
    {
        $date = $this->referenceDate($period);
        $errors = [];
        $warnings = [];

        $consumption = $this->consumption->billableReadings((int) $apartment['id'], (int) $period['id']);
        array_push($errors, ...$consumption['errors']);

        $meters = [];
        foreach ($consumption['readings'] as $reading) {
            $tariff = $this->tariffs->effectiveAt($reading['utility_type'], $reading['usage_type'], $date);
            $series = label_source('utility_type', $reading['utility_type']) . ' ' . mb_strtolower(label_source('usage_type', $reading['usage_type']));
            if ($tariff === null) {
                $errors[] = "Chưa có biểu giá được duyệt cho {$series} kỳ {$period['period_code']}.";
                continue;
            }
            $tiers = $reading['usage_type'] === 'kinh_doanh' ? [] : $this->tariffs->tiersOf((int) $tariff['id']);
            try {
                $calc = TariffCalculator::calculate($reading['consumption_cents'], $tariff, $tiers);
            } catch (InvalidArgumentException | RuntimeException $e) { // dữ liệu bậc sai / vượt DECIMAL(14,2)
                $errors[] = "Công tơ {$reading['meter_code']} ({$series}, biểu giá #{$tariff['id']}): " . $e->getMessage();
                continue;
            }
            $meters[] = [
                'reading_id' => (int) $reading['id'],
                'meter_id' => (int) $reading['meter_id'],
                'meter_code' => $reading['meter_code'],
                'display_label' => $reading['display_label'],
                'utility_type' => $reading['utility_type'],
                'usage_type' => $reading['usage_type'],
                'previous_reading' => $reading['previous_reading'],
                'current_reading' => $reading['current_reading'],
                'consumption' => $reading['consumption_cents'],
                'tariff_id' => (int) $tariff['id'],
                'tariff_effective_date' => $tariff['effective_date'],
            ] + $calc;
        }

        // FN-05.03
        $rates = [];
        $feeTypes = array_merge(array_values(config('billing.service_fee')), [config('billing.fire_insurance.fee_type')], array_values(config('billing.parking')));
        foreach (array_unique($feeTypes) as $feeType) {
            $rates[$feeType] = $this->fees->effectiveAt($feeType, $date);
        }
        try {
            $fixed = FixedFeeCalculator::calculate($apartment, $this->charges->vehiclesOf((int) $apartment['id']), $rates);
        } catch (RuntimeException $e) { // vượt DECIMAL(14,2)
            $fixed = ['lines' => [], 'errors' => ['Phí cố định: ' . $e->getMessage()], 'not_calculated' => []];
        }
        array_push($errors, ...$fixed['errors']);

        // J-16: khai báo "không phát sinh" nhưng có CL > 0 -> chỉ cảnh báo, không tự đổi mức phí
        $hasConsumption = array_filter($meters, static fn (array $m): bool => $m['consumption'] > 0) !== [];
        if (($apartment['occupancy_status'] ?? '') === 'khong_phat_sinh' && $hasConsumption) {
            $warnings[] = 'Căn khai báo "không phát sinh điện nước" nhưng có CL > 0 — phí DV vẫn theo khai báo của BQL — cần BQL kiểm tra.';
        }

        // FN-05.04
        $aggregate = ChargeAggregationService::aggregate($meters, $fixed['lines'], $oldDebtCents);
        array_push($errors, ...$aggregate['errors']);

        $result = [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'meters' => $meters,
            'fees' => $fixed['lines'],
            'not_calculated' => $fixed['not_calculated'],
            'columns' => $aggregate['columns'],
            'totals' => $aggregate['totals'],
        ];
        $result['snapshot'] = $this->snapshot($period, $apartment, $result);

        return $result;
    }

    // ------------------------------------------------------------------
    // Tính + ghi
    // ------------------------------------------------------------------

    /**
     * Tính phí 1 căn (1 transaction).
     * @return array{action:string, charge_id:int, warnings:string[]} action = created | recalculated | unchanged
     */
    public function calculateApartment(int $periodId, int $apartmentId): array
    {
        return $this->transaction($this->charges, function () use ($periodId, $apartmentId): array {
            $period = $this->periods->findForUpdate($periodId);
            if ($period === null) {
                throw ValidationException::withMessage('Kỳ không tồn tại.');
            }
            if ($period['status'] === 'da_khoa') {
                throw ValidationException::withMessage("Kỳ {$period['period_code']} đã khóa — không thể tính/tính lại phí. BQL mở lại kỳ trước.");
            }
            $apartment = $this->billableApartment($periodId, $apartmentId);
            $existing = $this->charges->findByApartmentPeriod($apartmentId, $periodId, true);
            if ($existing !== null) {
                IssuedChargeGuard::assertMutable((int)$existing['id']);
            }
            if ($existing !== null && !in_array($existing['status'], config('billing.recalculable_statuses'), true)) {
                throw ValidationException::withMessage("Căn {$apartment['code']}: bản tính đã phát hành phiếu — tính lại cần phiếu thay thế (chưa hỗ trợ).");
            }

            $oldDebt = $existing !== null ? MoneyCalculator::fromDb($existing['old_debt_amount']) : 0;
            $result = $this->compute($period, $apartment, $oldDebt);
            if (!$result['ok']) {
                throw new ValidationException(['apartment' => array_map(static fn (string $m): string => "Căn {$apartment['code']}: {$m}", $result['errors'])]);
            }

            if ($existing === null) {
                $chargeId = $this->charges->insertRow(['apartment_id' => $apartmentId, 'billing_period_id' => $periodId, 'status' => 'da_tinh']
                    + $this->columnValues($result['columns']));
                $this->writeTierDetails($chargeId, $result['meters']);
                $this->audit('CALCULATE_APARTMENT_CHARGE', 'apartment_charge', $chargeId, null,
                    ['status' => 'da_tinh', 'snapshot' => $result['snapshot']]);

                return ['action' => 'created', 'charge_id' => $chargeId, 'warnings' => $result['warnings']];
            }

            $chargeId = (int) $existing['id'];
            $differences = $this->differences($existing, $result);
            if ($differences === [] && $existing['status'] === 'bi_tra_lai') {
                // G6: Bị trả lại -> (Kế toán kiểm tra lại) tính lại -> Đã tính, kể cả khi số liệu không đổi
                $differences = ['Tính lại sau khi bị trả lại (số liệu không đổi).'];
            }
            if ($differences === []) {
                return ['action' => 'unchanged', 'charge_id' => $chargeId, 'warnings' => $result['warnings']];
            }

            $this->charges->updateById($chargeId, $this->columnValues($result['columns']) + [
                'status' => 'da_tinh',
                'confirmed_by' => null, 'confirmed_at' => null,
                'approved_by' => null, 'approved_at' => null,
                'rejection_reason' => null,
            ]);
            $this->charges->deleteTierDetails($chargeId);
            $this->writeTierDetails($chargeId, $result['meters']);
            $this->audit('RECALCULATE_APARTMENT_CHARGE', 'apartment_charge', $chargeId,
                ['status' => $existing['status'], 'confirmed_at' => $existing['confirmed_at'], 'rejection_reason' => $existing['rejection_reason'],
                    'columns' => array_intersect_key($existing, array_flip(ApartmentChargeRepository::AMOUNT_COLUMNS))],
                ['status' => 'da_tinh', 'reasons' => $differences, 'snapshot' => $result['snapshot']]);

            return ['action' => 'recalculated', 'charge_id' => $chargeId, 'warnings' => $result['warnings']];
        });
    }

    /**
     * Tính phí cả kỳ (hoặc 1 căn khi $apartmentId != null): mỗi căn 1 transaction; căn lỗi không chặn căn khác.
     * @return array{created:int[], recalculated:int[], unchanged:int[], errors:array<string,string[]>, warnings:string[]}
     */
    public function calculatePeriod(int $periodId, ?int $apartmentId = null): array
    {
        $period = $this->periods->find($periodId);
        if ($period === null) {
            throw ValidationException::withMessage('Kỳ không tồn tại.');
        }
        if ($period['status'] === 'da_khoa') {
            throw ValidationException::withMessage("Kỳ {$period['period_code']} đã khóa — không thể tính phí.");
        }

        $targets = $apartmentId !== null ? [$apartmentId] : array_map('intval', array_column($this->charges->billableApartments($periodId), 'id'));
        $summary = ['created' => [], 'recalculated' => [], 'unchanged' => [], 'errors' => [], 'warnings' => []];
        foreach ($targets as $id) {
            try {
                $r = $this->calculateApartment($periodId, $id);
                $summary[$r['action']][] = $r['charge_id'];
                array_push($summary['warnings'], ...$r['warnings']);
            } catch (ValidationException $e) {
                $summary['errors'][(string) $id] = array_merge(...array_values(array_map(static fn ($v): array => (array) $v, $e->errors())));
            }
        }

        if ($apartmentId === null) {
            // Audit tóm tắt theo lô; từng căn đã có CALCULATE/RECALCULATE riêng
            AuditLogger::log('CALCULATE_BILLING_PERIOD', 'billing_period', (string) $periodId, null, [
                'apartments' => count($targets),
                'created' => count($summary['created']),
                'recalculated' => count($summary['recalculated']),
                'unchanged' => count($summary['unchanged']),
                'errors' => count($summary['errors']),
            ]);
        }

        return $summary;
    }

    // ------------------------------------------------------------------
    // Tính nhất quán (Q12 / P19)
    // ------------------------------------------------------------------

    /**
     * @return array{state:string, reasons:string[], result:?array}
     *   state: locked (kỳ khoá - dữ liệu lịch sử, không so) | current | stale (cần tính lại) | stale_error (cần tính lại nhưng đang lỗi dữ liệu)
     */
    public function consistency(array $period, array $charge): array
    {
        if ($period['status'] === 'da_khoa') {
            return ['state' => 'locked', 'reasons' => [], 'result' => null];
        }
        $apartment = $this->apartments->find((int) $charge['apartment_id']);
        $result = $this->compute($period, $apartment, MoneyCalculator::fromDb($charge['old_debt_amount']));
        if (!$result['ok']) {
            return ['state' => 'stale_error', 'reasons' => $result['errors'], 'result' => $result];
        }
        $differences = $this->differences($charge, $result);

        return ['state' => $differences === [] ? 'current' : 'stale', 'reasons' => $differences, 'result' => $result];
    }

    /** Danh sách căn của kỳ cho màn hình tính phí / review (bản tính + trạng thái nhất quán). */
    public function overview(int $periodId): array
    {
        $period = $this->periods->findDetailed($periodId);
        if ($period === null) {
            return [];
        }
        $charges = [];
        foreach ($this->charges->listByPeriod($periodId) as $charge) {
            $charges[(int) $charge['apartment_id']] = $charge;
        }

        $rows = [];
        foreach ($this->charges->billableApartments($periodId) as $apartment) {
            $charge = $charges[(int) $apartment['id']] ?? null;
            if ($charge === null) {
                $result = $period['status'] === 'da_khoa' ? null : $this->compute($period, $apartment, 0);
                $rows[] = [
                    'apartment' => $apartment, 'charge' => null,
                    'state' => $result === null ? 'missing' : ($result['ok'] ? 'not_calculated' : 'error'),
                    'reasons' => $result['errors'] ?? [], 'warnings' => $result['warnings'] ?? [],
                ];
                continue;
            }
            $consistency = $this->consistency($period, $charge);
            $rows[] = [
                'apartment' => $apartment,
                'charge' => $charge + ['version_token' => self::versionToken($charge)],
                'state' => $consistency['state'],
                'reasons' => $consistency['reasons'],
                'warnings' => $consistency['result']['warnings'] ?? [],
            ];
        }

        return $rows;
    }

    /** Chi tiết bản tính (FN-05.06 Review Detail / Apartment Charge Detail). */
    public function detail(int $chargeId): ?array
    {
        $charge = $this->charges->findDetailed($chargeId);
        if ($charge === null) {
            return null;
        }
        $period = $this->periods->find((int) $charge['billing_period_id']);
        $snapshotLog = $this->audits->latestForEntity('apartment_charge', (string) $chargeId, self::SNAPSHOT_ACTIONS);
        $snapshot = $snapshotLog !== null ? (json_decode((string) $snapshotLog['new_value'], true)['snapshot'] ?? null) : null;

        $columns = [];
        foreach (ApartmentChargeRepository::AMOUNT_COLUMNS as $column) {
            $columns[$column] = MoneyCalculator::fromDb($charge[$column]);
        }

        $meters = [];
        foreach ($this->charges->tierDetails($chargeId) as $line) {
            $key = (int) $line['meter_reading_id'];
            $meters[$key] ??= ['reading' => $line, 'lines' => [], 'subtotal' => 0];
            $meters[$key]['lines'][] = $line;
            $meters[$key]['subtotal'] += MoneyCalculator::fromDb($line['amount']);
        }
        // VAT / biểu giá từng công tơ: chỉ có trong snapshot (schema lưu VAT điện gộp theo căn - DESIGN GAP G-2)
        foreach ($snapshot['meters'] ?? [] as $m) {
            if (isset($meters[(int) $m['reading_id']])) {
                $meters[(int) $m['reading_id']]['snapshot'] = $m;
            }
        }

        $fees = $snapshot['fees'] ?? null;
        $feeCheck = null;
        if ($fees !== null) {
            $sum = ['service' => 0, 'fire_insurance' => 0, 'parking' => 0];
            foreach ($fees as $fee) {
                $sum[$fee['group']] += MoneyCalculator::fromDb($fee['amount']);
            }
            $feeCheck = $sum['service'] === $columns['service_fee_amount'] && $sum['fire_insurance'] === $columns['fire_insurance_amount']
                && $sum['parking'] === $columns['parking_fee_amount'];
        }

        return [
            'charge' => $charge + ['version_token' => self::versionToken($charge)],
            'period' => $period,
            'columns' => $columns,
            'totals' => ChargeAggregationService::totalsFromColumns($columns),
            'meters' => array_values($meters),
            'snapshot' => $snapshot,
            'snapshot_at' => $snapshotLog['created_at'] ?? null,
            'fees' => $fees,
            'fee_check' => $feeCheck,
            'consistency' => $this->consistency($period, $charge),
            'open_alerts' => $this->charges->openAlertCount((int) $charge['apartment_id'], (int) $charge['billing_period_id']),
            'j16_blocking' => (new NoUsageConsumptionService())->blockingAlert((int) $charge['apartment_id'], (int) $charge['billing_period_id']),
            'history' => $this->audits->forEntity('apartment_charge', (string) $chargeId),
        ];
    }

    /** Token chống xác nhận/sửa trên dữ liệu đã đổi (hash nội dung - updated_at chỉ có độ phân giải giây). */
    public static function versionToken(array $charge): string
    {
        $parts = [$charge['id'], $charge['status'], $charge['confirmed_at'] ?? '', $charge['approved_at'] ?? '', $charge['rejection_reason'] ?? '', $charge['updated_at'] ?? ''];
        foreach (ApartmentChargeRepository::AMOUNT_COLUMNS as $column) {
            $parts[] = $charge[$column];
        }

        return sha1(implode('|', $parts));
    }

    // ------------------------------------------------------------------

    /** [ASSUMPTION J-15, Q2] ngày tham chiếu chọn biểu giá/phí. */
    private function referenceDate(array $period): string
    {
        return $period['end_date'];
    }

    private function billableApartment(int $periodId, int $apartmentId): array
    {
        foreach ($this->charges->billableApartments($periodId) as $apartment) {
            if ((int) $apartment['id'] === $apartmentId) {
                return $apartment;
            }
        }
        throw ValidationException::withMessage('Căn hộ không thuộc danh sách tính phí của kỳ (ngừng hoạt động, không có chỉ số).');
    }

    /** Khác biệt giữa bản đang lưu và kết quả tính lại (rỗng = hiện hành). */
    private function differences(array $charge, array $result): array
    {
        $labels = [
            'electricity_consumption' => 'CL điện', 'electricity_amount' => 'Tiền điện', 'electricity_vat_amount' => 'VAT điện',
            'water_consumption' => 'CL nước', 'water_amount' => 'Tiền nước', 'service_fee_amount' => 'Phí DV',
            'fire_insurance_amount' => 'BHCN', 'parking_fee_amount' => 'Phí gửi xe', 'total_arising' => 'Tổng phát sinh',
            'old_debt_amount' => 'Nợ cũ', 'total_due' => 'Tổng phải thu',
        ];
        $reasons = [];
        foreach (ApartmentChargeRepository::AMOUNT_COLUMNS as $column) {
            $stored = MoneyCalculator::fromDb($charge[$column]);
            $now = $result['columns'][$column];
            if ($stored !== $now) {
                $reasons[] = "{$labels[$column]}: đang lưu " . Decimal::fromCents($stored) . ' → tính lại ' . Decimal::fromCents($now);
            }
        }

        $stored = array_map(static fn (array $d): string => implode('|', [
            $d['meter_reading_id'], $d['utility_type'], $d['tier_order'], MoneyCalculator::fromDb($d['tier_from']),
            $d['tier_to'] === null ? '' : MoneyCalculator::fromDb($d['tier_to']), MoneyCalculator::fromDb($d['quantity']),
            MoneyCalculator::fromDb($d['unit_price']), MoneyCalculator::fromDb($d['amount']),
        ]), $this->charges->tierDetails((int) $charge['id']));
        $now = [];
        foreach ($result['meters'] as $m) {
            foreach ($m['lines'] as $l) {
                $now[] = implode('|', [$m['reading_id'], $m['utility_type'], $l['tier_order'], $l['tier_from'], $l['tier_to'] ?? '', $l['quantity'], $l['unit_price'], $l['amount']]);
            }
        }
        sort($stored);
        sort($now);
        if ($stored !== $now) {
            $reasons[] = 'Chi tiết bậc / chỉ số / đơn giá theo công tơ đã thay đổi.';
        }

        // Phiên bản biểu giá / phí (P19): chỉ so khi có snapshot (bản do FN-05 tạo)
        $log = $this->audits->latestForEntity('apartment_charge', (string) $charge['id'], self::SNAPSHOT_ACTIONS);
        $snapshot = $log !== null ? (json_decode((string) $log['new_value'], true)['snapshot'] ?? null) : null;
        if ($snapshot !== null) {
            $storedVersions = $snapshot['versions'] ?? [];
            $nowVersions = $result['snapshot']['versions'];
            if ($storedVersions != $nowVersions) {
                $reasons[] = 'Phiên bản biểu giá / đơn giá phí áp dụng đã thay đổi (P19).';
            }
        }

        return $reasons;
    }

    private function columnValues(array $columns): array
    {
        return array_map([Decimal::class, 'fromCents'], $columns);
    }

    private function writeTierDetails(int $chargeId, array $meters): void
    {
        foreach ($meters as $m) {
            foreach ($m['lines'] as $l) {
                $this->charges->insertTierDetail([
                    'charge' => $chargeId,
                    'reading' => $m['reading_id'],
                    'utility' => $m['utility_type'],
                    'tier_order' => $l['tier_order'],
                    'tier_from' => Decimal::fromCents($l['tier_from']),
                    'tier_to' => $l['tier_to'] === null ? null : Decimal::fromCents($l['tier_to']),
                    'quantity' => Decimal::fromCents($l['quantity']),
                    'unit_price' => Decimal::fromCents($l['unit_price']),
                    'amount' => Decimal::fromCents($l['amount']),
                ]);
            }
        }
    }

    /**
     * Snapshot cơ sở tính (DESIGN GAP G-1/G-2/G-3, mục 8.3): schema chỉ lưu số tiền theo khoản; DT, số xe,
     * đơn giá phí, phiên bản biểu giá, VAT từng công tơ được lưu trong audit_logs.new_value (không xoá được - BR-04).
     */
    private function snapshot(array $period, array $apartment, array $r): array
    {
        $money = static fn (int $c): string => Decimal::fromCents($c);
        $versions = [];
        foreach ($r['meters'] as $m) {
            $versions['tariff:' . $m['reading_id']] = $m['tariff_id'];
        }
        foreach ($r['fees'] as $f) {
            $versions['fee:' . $f['fee_type']] = $f['rate_id'];
        }
        ksort($versions);

        return [
            'period' => ['id' => (int) $period['id'], 'code' => $period['period_code'], 'reference_date' => $this->referenceDate($period)],
            'apartment' => ['id' => (int) $apartment['id'], 'code' => $apartment['code'], 'area' => $apartment['area'], 'occupancy_status' => $apartment['occupancy_status']],
            'rules' => [
                'rate_reference_date' => 'period_end',
                'rounding' => MoneyCalculator::describe() . '',
                'vat' => 'theo công tơ; vat_percent NULL = không tách VAT',
                'service_fee' => 'occupancy_status',
                'fire_insurance' => !empty(config('billing.fire_insurance.enabled')) ? 'hằng kỳ' : 'tắt',
            ],
            'versions' => $versions,
            'meters' => array_map(static fn (array $m): array => [
                'reading_id' => $m['reading_id'], 'meter_id' => $m['meter_id'], 'meter_code' => $m['meter_code'],
                'utility_type' => $m['utility_type'], 'usage_type' => $m['usage_type'],
                'previous_reading' => $m['previous_reading'], 'current_reading' => $m['current_reading'], 'consumption' => $money($m['consumption']),
                'tariff_id' => $m['tariff_id'], 'tariff_effective_date' => $m['tariff_effective_date'], 'mode' => $m['mode'],
                'vat_percent' => $m['vat_percent'] === null ? null : $money($m['vat_percent']),
                'lines' => array_map(static fn (array $l): array => [
                    'tier_order' => $l['tier_order'], 'tier_from' => $money($l['tier_from']), 'tier_to' => $l['tier_to'] === null ? null : $money($l['tier_to']),
                    'quantity' => $money($l['quantity']), 'unit_price' => $money($l['unit_price']), 'amount' => $money($l['amount']),
                ], $m['lines']),
                'subtotal' => $money($m['subtotal']), 'vat' => $money($m['vat']), 'total' => $money($m['total']),
            ], $r['meters']),
            'fees' => array_map(static fn (array $f): array => [
                'group' => $f['group'], 'fee_type' => $f['fee_type'], 'rate_id' => $f['rate_id'], 'rate_effective_date' => $f['rate_effective_date'],
                'base_quantity' => $money($f['base_quantity']), 'base_unit' => $f['base_unit'],
                'unit_price' => $money($f['unit_price']), 'amount' => $money($f['amount']),
            ], $r['fees']),
            'not_calculated' => $r['not_calculated'],
            'warnings' => $r['warnings'],
            'totals' => array_map($money, $r['totals']),
        ];
    }
}
