<?php
declare(strict_types=1);

// Pure regression tests: no database connection, fixtures, or writes.
require dirname(__DIR__) . '/bootstrap.php';

use App\Repositories\ConsumptionAnalyticsRepository;
use App\Services\BaselineService;
use App\Services\ScopeConsumptionService;

class BaselineHistoryFixture extends ConsumptionAnalyticsRepository
{
    public array $periods = [];
    public array $sums = [];
    public array $missing = [];
    public function __construct() {}
    public function periodsEndingBefore(string $date): array
    {
        return array_values(array_filter($this->periods, fn ($p) => $p['end_date'] < $date));
    }
    public function consumptionByScope(string $scopeType, string $utility, int $periodId, ?int $scopeId = null): array
    {
        return isset($this->sums[$periodId]) ? [5 => $this->sums[$periodId]] : [];
    }
    public function missingByScope(string $scopeType, string $utility, int $periodId, ?int $scopeId = null): array
    {
        return [5 => $this->missing[$periodId] ?? 0];
    }
}

$history = new BaselineHistoryFixture();
foreach ([11, 10, 9, 8, 7] as $month) {
    $history->periods[] = ['id' => $month, 'period_code' => sprintf('%02d/2026', $month),
        'end_date' => sprintf('2026-%02d-28', $month), 'status' => 'da_khoa'];
    $history->sums[$month] = ['confirmed_sum' => $month === 11 ? '20.00' : (string) ($month * 100),
        'confirmed_count' => $month === 11 ? 1 : 10, 'draft_count' => 0, 'changed_count' => 0];
}
$history->missing[11] = 9;
$reflection = new ReflectionClass(BaselineService::class);
$service = $reflection->newInstanceWithoutConstructor();
foreach (['analytics' => $history, 'consumption' => new ScopeConsumptionService($history)] as $name => $value) {
    $reflection->getProperty($name)->setValue($service, $value);
}
$count = 0;
function verify(bool $ok, string $label): void
{
    global $count;
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    ++$count;
}
$compute = fn ($n = 3, $scope = 'building', $method = 'trung_binh_n_ky', $date = '2026-12-01') =>
    $service->compute($scope, 5, 'dien', $method, $n, null, $date);
$result = $compute();
verify($result['status'] === 'ok', 'complete older periods are eligible');
verify(array_column($result['periods_used'], 'period_code') === ['10/2026', '09/2026', '08/2026'], 'latest three complete locked periods');
verify($result['value'] === 90000, 'average excludes partial total');
verify($result['skipped'][0]['period_code'] === '11/2026' && str_contains($result['skipped'][0]['reason'], '9 công tơ thiếu'), 'excluded period explains missing readings');
verify(str_contains($result['periods_used'][0]['reason'], '10 chỉ số'), 'included period explains completeness');
$history->sums[10]['draft_count'] = 1;
$result = $compute();
verify(array_column($result['periods_used'], 'id') === [9, 8, 7], 'draft readings exclude locked period');
verify(str_contains($result['skipped'][1]['reason'], '1 bản nháp'), 'draft reason visible');
$history->sums[10]['draft_count'] = 0;
$history->periods[1]['status'] = 'mo_lai';
verify(array_column($compute()['periods_used'], 'id') === [9, 8, 7], 'reopened period excluded');
$history->periods[1]['status'] = 'da_khoa';
verify($compute(5)['status'] === 'insufficient_history' && $compute(5)['value'] === null, 'insufficient history never produces baseline');
verify(array_column($compute(3, 'building', 'trung_binh_n_ky', '2026-10-01')['periods_used'], 'id') === [9, 8, 7], 'effective date cutoff respected');
verify($compute(3, 'apartment')['periods_used'][0]['id'] === 11, 'other scopes unchanged');
verify($compute(1, 'building', 'ky_truoc')['periods_used'][0]['id'] === 11, 'other methods unchanged');
$history->missing[11] = 0;
$history->sums[11]['confirmed_count'] = 0;
verify($compute()['periods_used'][0]['id'] === 10, 'no confirmed readings excluded');
$history->sums[11]['confirmed_count'] = 10;
$history->sums[11]['changed_count'] = 1;
verify($compute()['periods_used'][0]['id'] === 10, 'changed meter remains ineligible');
$history->sums[11]['changed_count'] = 0;
$history->sums[11]['confirmed_sum'] = '0.00';
verify($compute(1)['value'] === 0, 'complete zero consumption is eligible');
$history->sums[11]['confirmed_sum'] = '0.01';
$history->sums[10]['confirmed_sum'] = '0.00';
verify($compute(2)['value'] === 1, 'decimal average rounds half up');
echo "$count/$count PASS (no database connection)\n";
