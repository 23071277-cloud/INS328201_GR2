<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Decimal;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\DashboardRepository;
use App\Support\CsvWriter;

/**
 * FN-09.03 Báo cáo tiện ích toàn tòa ("danh sách thu" theo căn) - FR-12, BR-38, BR-37. CHỈ ĐỌC.
 *   Tiêu thụ: FN-06 ScopeConsumptionService::measureAll (CL Đã xác nhận, đủ dữ liệu) - tách điện / nước.
 *   Tiền: FN-05 apartment_charges đã lưu (không tính lại). Trạng thái bản tính:
 *     official    = Đã xác nhận+ và (kỳ đã khoá hoặc FN-05 consistency = hiện hành)
 *     unconfirmed = Đã tính / Bị trả lại;  stale = CẦN TÍNH LẠI;  missing = chưa có bản tính
 *   Dòng tổng chính thức chỉ cộng bản 'official' (U2). Kỹ thuật: không có cột tiền / họ tên (2.3 View giới hạn).
 */
class UtilityReportService
{
    private DashboardRepository $repo;
    private BillingPeriodRepository $periods;

    public function __construct()
    {
        $this->repo = new DashboardRepository();
        $this->periods = new BillingPeriodRepository();
    }

    public function options(?int $buildingId): array
    {
        return ['periods' => $this->periods->options(), 'buildings' => $this->repo->buildings(), 'zones' => $this->repo->zones($buildingId)];
    }

    /**
     * @param array{period_id:string,building_id:string,zone_id:string,apartment:string,utility_type:string} $f
     * @param array{cost:bool, names:bool} $show
     */
    public function build(array $f, array $show): array
    {
        $buildings = $this->repo->buildings();
        $buildingId = ctype_digit($f['building_id']) && in_array((int) $f['building_id'], array_map('intval', array_column($buildings, 'id')), true)
            ? (int) $f['building_id'] : (int) ($buildings[0]['id'] ?? 0);
        $zoneIds = array_map('intval', array_column($this->repo->zones($buildingId), 'id'));
        $zoneId = ctype_digit($f['zone_id']) && in_array((int) $f['zone_id'], $zoneIds, true) ? (int) $f['zone_id'] : null;
        $utilities = array_key_exists($f['utility_type'], config('master_data.utility_type')) ? [$f['utility_type']] : array_keys(config('master_data.utility_type'));
        $period = ctype_digit($f['period_id']) ? $this->periods->find((int) $f['period_id']) : null;
        $period ??= $this->repo->latestPeriodWithData();

        $out = ['period' => $period, 'filters' => ['period_id' => (string) ($period['id'] ?? ''), 'building_id' => (string) $buildingId, 'zone_id' => (string) ($zoneId ?? ''),
            'apartment' => $f['apartment'], 'utility_type' => count($utilities) === 1 ? $utilities[0] : ''], 'utilities' => $utilities, 'show' => $show, 'rows' => [], 'totals' => null];
        if ($period === null || $buildingId === 0) {
            return $out;
        }
        $periodId = (int) $period['id'];

        $charges = new ApartmentChargeRepository();
        $billable = array_column($charges->billableApartments($periodId), null, 'id');
        $inScope = $this->repo->apartmentsInScope($buildingId, $zoneId);
        $code = trim($f['apartment']);
        $apartments = array_filter($inScope, static fn ($a) => isset($billable[$a['id']]) && ($code === '' || mb_stripos($a['code'], $code) !== false));

        $measure = new ScopeConsumptionService();
        $consumption = [];
        foreach ($utilities as $u) {
            $consumption[$u] = $measure->measureAll('apartment', $u, $period);
        }
        $chargeRows = [];
        if ($show['cost']) {
            foreach ($charges->listByPeriod($periodId) as $c) {
                $chargeRows[(int) $c['apartment_id']] = $c;
            }
        }
        $owners = $show['names'] ? $this->repo->owners(array_keys($apartments)) : [];
        $billing = $show['cost'] ? new BillingCalculationService() : null;

        $totals = ['consumption' => array_fill_keys($utilities, 0), 'incomplete' => array_fill_keys($utilities, 0),
            'electricity_cost' => 0, 'water_cost' => 0, 'fixed_fee' => 0, 'total_arising' => 0, 'official' => 0, 'excluded' => 0];
        foreach ($apartments as $id => $a) {
            $row = ['apartment' => $a, 'owner' => $owners[$id] ?? null, 'consumption' => [], 'charge' => null, 'charge_state' => 'missing'];
            foreach ($utilities as $u) {
                $m = $consumption[$u][$id] ?? ScopeConsumptionService::emptyResult();
                $row['consumption'][$u] = $m;
                if ($m['value'] !== null) {
                    $totals['consumption'][$u] += $m['value'];
                } elseif ($m['status'] !== 'no_data') {
                    $totals['incomplete'][$u]++;
                }
            }
            if ($show['cost'] && isset($chargeRows[$id])) {
                $c = $chargeRows[$id];
                $row['charge'] = $c;
                $row['charge_state'] = $this->chargeState($c, $period, $billing);
                if ($row['charge_state'] === 'official') {
                    $totals['official']++;
                    $totals['electricity_cost'] += MoneyCalculator::fromDb($c['electricity_amount']) + MoneyCalculator::fromDb($c['electricity_vat_amount']);
                    $totals['water_cost'] += MoneyCalculator::fromDb($c['water_amount']);
                    $totals['fixed_fee'] += MoneyCalculator::fromDb($c['service_fee_amount']) + MoneyCalculator::fromDb($c['fire_insurance_amount']) + MoneyCalculator::fromDb($c['parking_fee_amount']);
                    $totals['total_arising'] += MoneyCalculator::fromDb($c['total_arising']);
                } else {
                    $totals['excluded']++;
                }
            } elseif ($show['cost']) {
                $totals['excluded']++;
            }
            $out['rows'][] = $row;
        }
        $out['totals'] = $totals;

        return $out;
    }

    /** CSV cùng dữ liệu (FN-09.03 Export). */
    public function csv(array $report): string
    {
        $show = $report['show'];
        $header = ['Kỳ', 'Tòa', 'Khu', 'Căn hộ'];
        if ($show['names']) {
            $header[] = 'Chủ hộ';
        }
        foreach ($report['utilities'] as $u) {
            $header[] = 'CL ' . (config('alerts.utility_word')[$u] ?? $u) . ' (' . config('pricing.utility_unit')[$u] . ')';
        }
        if ($show['cost']) {
            array_push($header, 'Tiền điện gồm VAT (đ)', 'Tiền nước (đ)', 'Phí cố định (đ)', 'Tổng phát sinh (đ)', 'Nợ cũ (đ)', 'Tổng phải thu (đ)', 'Trạng thái bản tính');
        }
        $code = $report['period']['period_code'] ?? '';
        $states = self::stateLabels();
        $lines = [];
        foreach ($report['rows'] as $r) {
            $line = [CsvWriter::text($code), CsvWriter::text($r['apartment']['building_code']), CsvWriter::text($r['apartment']['zone_name'] ?? ''), CsvWriter::text($r['apartment']['code'])];
            if ($show['names']) {
                $line[] = CsvWriter::text($r['owner'] ?? '');
            }
            foreach ($report['utilities'] as $u) {
                $line[] = CsvWriter::number(ScopeConsumptionService::fmt($r['consumption'][$u]['value']));
            }
            if ($show['cost']) {
                $c = $r['charge'];
                $line = array_merge($line, $c === null ? ['', '', '', '', '', ''] : [
                    Decimal::fromCents(MoneyCalculator::fromDb($c['electricity_amount']) + MoneyCalculator::fromDb($c['electricity_vat_amount'])),
                    $c['water_amount'],
                    Decimal::fromCents(MoneyCalculator::fromDb($c['service_fee_amount']) + MoneyCalculator::fromDb($c['fire_insurance_amount']) + MoneyCalculator::fromDb($c['parking_fee_amount'])),
                    $c['total_arising'], $c['old_debt_amount'], $c['total_due'],
                ], [$states[$r['charge_state']]]);
            }
            $lines[] = $line;
        }
        if ($report['totals'] !== null) {
            $t = $report['totals'];
            $total = ['TỔNG', '', '', count($report['rows']) . ' căn'];
            if ($show['names']) {
                $total[] = '';
            }
            foreach ($report['utilities'] as $u) {
                $total[] = Decimal::fromCents($t['consumption'][$u]);
            }
            if ($show['cost']) {
                array_push($total, Decimal::fromCents($t['electricity_cost']), Decimal::fromCents($t['water_cost']), Decimal::fromCents($t['fixed_fee']),
                    Decimal::fromCents($t['total_arising']), '', '', "Chỉ cộng {$t['official']} bản chính thức; loại {$t['excluded']}");
            }
            $lines[] = $total;
        }

        return CsvWriter::build($header, $lines);
    }

    public static function stateLabels(): array
    {
        return ['official' => 'Chính thức', 'unconfirmed' => 'Chưa xác nhận', 'stale' => 'CẦN TÍNH LẠI', 'missing' => 'Chưa có bản tính'];
    }

    private function chargeState(array $charge, array $period, BillingCalculationService $billing): string
    {
        if (!in_array($charge['status'], config('billing.lockable_statuses'), true)) {
            return 'unconfirmed';
        }
        if ($period['status'] === 'da_khoa') {
            return 'official'; // khoá kỳ (FN-05.07) đã bảo đảm hiện hành
        }

        return $billing->consistency($period, $charge)['state'] === 'current' ? 'official' : 'stale';
    }
}
