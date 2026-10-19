<?php

declare(strict_types=1);

/**
 * STEP 9 - Test cấp Service FN-05 (tiêu thụ, tính tiền, phí cố định, tổng hợp, xác nhận, khoá/mở lại kỳ,
 * tổng hợp chi phí, idempotent, tính nhất quán P19, đồng thời bằng tiến trình PHP thật).
 * Chạy trên DATABASE TẠM:  php tests/fn05_service_test.php energy_dss_fn05_test
 * Seed: kỳ 1 = 06/2024 (đã khoá, 4 bản tính đã phát hành), kỳ 2 = 07/2024 (mở, chưa có chỉ số).
 */

$testDb = $argv[1] ?? '';
if ($testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Cần tên database TẠM (khác energy_dss).\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';
$_ENV['DB_DATABASE'] = $testDb;
restore_error_handler();
restore_exception_handler();

use App\Core\Config;
use App\Core\Database;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\BillingPeriodRepository;
use App\Services\ApartmentService;
use App\Services\BillingCalculationService;
use App\Services\BillingConfirmationService;
use App\Services\ChargeAggregationService;
use App\Services\CostAllocationService;
use App\Services\FixedFeeCalculator;
use App\Services\MeterReadingService;
use App\Services\MeterService;
use App\Services\MoneyCalculator;
use App\Services\ParkingVehicleService;
use App\Services\PeriodClosingService;
use App\Services\ReadingApprovalService;
use App\Services\ReadingImportService;
use App\Services\TariffCalculator;
use App\Services\TariffService;

require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SERVER['REMOTE_ADDR'] = 'fn05-service-test';
const BQL = 1;
const KT = 2;
const KTO = 3;
const P2 = 2; // kỳ 07/2024

function actAs(int $id, string $role): void
{
    $_SESSION = ['user_id' => $id, 'user_role' => $role, 'username' => $role, 'full_name' => $role];
}

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function expectError(string $g, string $n, string $field, callable $fn, string $contains = ''): ?array
{
    try {
        $fn();
        check($g, $n, false, 'không có lỗi');
    } catch (ValidationException $e) {
        $msg = json_encode($e->errors(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ok = isset($e->errors()[$field]) && ($contains === '' || str_contains($msg, $contains));
        check($g, $n, $ok, $ok ? '' : $msg);

        return $e->errors();
    }

    return null;
}

function expectOk(string $g, string $n, callable $fn)
{
    try {
        $r = $fn();
        check($g, $n, true);

        return $r;
    } catch (ValidationException $e) {
        check($g, $n, false, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

        return null;
    }
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function rows(PDO $pdo, string $sql, array $p = []): array
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchAll();
}

function chargeOf(PDO $pdo, int $apartmentId, int $periodId = P2): array
{
    $s = $pdo->prepare('SELECT * FROM apartment_charges WHERE apartment_id = ? AND billing_period_id = ?');
    $s->execute([$apartmentId, $periodId]);

    return $s->fetch() ?: [];
}

function readingOf(PDO $pdo, int $meterId, int $periodId = P2): array
{
    $s = $pdo->prepare('SELECT * FROM meter_readings WHERE meter_id = ? AND billing_period_id = ?');
    $s->execute([$meterId, $periodId]);

    return $s->fetch() ?: [];
}

function auditCount(PDO $pdo, string $action, string $entity, int $id): int
{
    return (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_type = ? AND entity_id = ?', [$action, $entity, (string) $id]);
}

function lastAudit(PDO $pdo, string $action, string $entity, int $id): array
{
    $r = rows($pdo, 'SELECT * FROM audit_logs WHERE action = ? AND entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1', [$action, $entity, (string) $id]);

    return $r === [] ? [] : ['old' => json_decode((string) $r[0]['old_value'], true), 'new' => json_decode((string) $r[0]['new_value'], true), 'user_id' => $r[0]['user_id'], 'created_at' => $r[0]['created_at']];
}

/** Σ amount bậc của 1 tiện ích = cột tiền tương ứng (không có dữ liệu nửa vời). */
function tierSumMatches(PDO $pdo, array $charge): bool
{
    if ($charge === []) {
        return false;
    }
    $sum = static fn (string $u): string => (string) q($pdo, 'SELECT COALESCE(SUM(amount), 0) FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = ?', [$charge['id'], $u]);

    return bccomp($sum('dien'), $charge['electricity_amount'], 2) === 0 && bccomp($sum('nuoc'), $charge['water_amount'], 2) === 0;
}

/** Hàm tham chiếu ĐỘC LẬP (bcmath) cho bậc thang nửa khoảng - giống tierAmount() của schema_review_test. */
function refTier(string $cl, array $tiers): string
{
    $total = '0';
    foreach ($tiers as [$from, $to, $price]) {
        $upper = $to === null ? $cl : (bccomp($cl, $to, 2) < 0 ? $cl : $to);
        $qty = bcsub($upper, $from, 2);
        if (bccomp($qty, '0', 2) > 0) {
            $total = bcadd($total, bcmul($qty, $price, 4), 4);
        }
    }

    return $total;
}

const BR13 = [['0', '50', '1806'], ['50', '100', '1866'], ['100', '200', '2167'], ['200', '300', '2729'], ['300', '400', '3050'], ['400', null, '3151']];

function race(string $db, array $jobs): array
{
    $start = microtime(true) + 1.5;
    $procs = [];
    $pipes = [];
    foreach ($jobs as $i => $job) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/fn05_worker.php') . ' ' . escapeshellarg($db) . ' ' . sprintf('%F', $start)
            . ' ' . implode(' ', array_map('escapeshellarg', array_map('strval', $job)));
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $p) {
        $raw = stream_get_contents($pipes[$i][1]);
        $out[$i] = json_decode((string) $raw, true) ?? ['ok' => false, 'message' => $raw . stream_get_contents($pipes[$i][2])];
        proc_close($p);
    }

    return $out;
}

$readingSvc = new MeterReadingService();
$approval = new ReadingApprovalService();
$calc = new BillingCalculationService();
$confirm = new BillingConfirmationService();
$closing = new PeriodClosingService();
$charges = new ApartmentChargeRepository();
$periods = new BillingPeriodRepository();

/** Kỹ thuật nhập + (tuỳ chọn) BQL duyệt; trả id chỉ số. */
function enter(int $meterId, string $current, bool $approve = true): int
{
    global $readingSvc, $approval;
    actAs(KT, 'ky_thuat');
    $r = $readingSvc->create(P2, $meterId, ['current_reading' => $current, 'note' => '']);
    if ($approve) {
        approveReading((int) $r['id']);
    }

    return (int) $r['id'];
}

function approveReading(int $readingId): void
{
    global $approval, $readingSvc;
    actAs(BQL, 'bql');
    $res = $approval->approve(P2, [$readingId => MeterReadingService::versionToken($readingSvc->find($readingId))]);
    if ($res['approved'] === []) {
        throw new RuntimeException('approve failed: ' . json_encode($res['skipped'], JSON_UNESCAPED_UNICODE));
    }
}

function token(int $chargeId): string
{
    global $charges;

    return BillingCalculationService::versionToken($charges->find($chargeId));
}

function state(int $apartmentId): string
{
    global $calc, $periods, $pdo;

    return $calc->consistency($periods->find(P2), chargeOf($pdo, $apartmentId))['state'];
}

function confirmAll(): void
{
    global $calc, $confirm;
    actAs(KTO, 'ke_toan');
    $items = [];
    foreach ($calc->overview(P2) as $row) {
        if ($row['charge'] !== null && $row['charge']['status'] === 'da_tinh') {
            $items[(int) $row['charge']['id']] = $row['charge']['version_token'];
        }
    }
    if ($items !== []) {
        $confirm->confirm(P2, $items);
    }
}

// =========================================================== MONEY / ROUNDING (J-15)
$G = 'MONEY';
check($G, 'multiply 343 kWh × 3.050 không float', MoneyCalculator::multiply(4300, 305000) === 13115000);
check($G, 'percent: 804.350 × 8% = 64.348', MoneyCalculator::percent(80435000, 800) === 6434800);
check($G, 'percent half_up tới xu: 100,05 × 8% = 8,004 → 8,00', MoneyCalculator::percent(10005, 800) === 800);
check($G, 'divide (TB/căn) 4.773.552 ÷ 4 = 1.193.388', MoneyCalculator::divide(477355200, 4) === 119338800);
check($G, 'không tràn số nguyên (bcmath): 9.999.999.999,99 × 99,99 = 999.899.999.999,00', MoneyCalculator::multiply(999999999999, 9999) === 99989999999900);
try {
    MoneyCalculator::multiply(999999999999, 999999999999);
    check($G, 'vượt DECIMAL(14,2) → exception (không ghi số sai)', false);
} catch (RuntimeException $e) {
    check($G, 'vượt DECIMAL(14,2) → exception (không ghi số sai)', true);
}
Config::set('billing.rounding', ['scale' => 0, 'mode' => 'half_up']);
$w = TariffCalculator::calculate(3050, ['usage_type' => 'sinh_hoat', 'vat_percent' => null], [
    ['tier_order' => 1, 'tier_from' => '0', 'tier_to' => '10', 'unit_price' => '11045'],
    ['tier_order' => 2, 'tier_from' => '10', 'tier_to' => '20', 'unit_price' => '12445'],
    ['tier_order' => 3, 'tier_from' => '20', 'tier_to' => '30', 'unit_price' => '15745'],
    ['tier_order' => 4, 'tier_from' => '30', 'tier_to' => null, 'unit_price' => '17245'],
]);
check($G, 'config rounding scale 0 (tới đồng): nước 30,5 m³ → 400.973 (tập trung ở MoneyCalculator)', $w['total'] === 40097300, (string) $w['total']);
Config::set('billing.rounding', ['scale' => 2, 'mode' => 'half_up']);
$w = TariffCalculator::calculate(3050, ['usage_type' => 'sinh_hoat', 'vat_percent' => null], [
    ['tier_order' => 1, 'tier_from' => '0', 'tier_to' => '10', 'unit_price' => '11045'],
    ['tier_order' => 2, 'tier_from' => '10', 'tier_to' => '20', 'unit_price' => '12445'],
    ['tier_order' => 3, 'tier_from' => '20', 'tier_to' => '30', 'unit_price' => '15745'],
    ['tier_order' => 4, 'tier_from' => '30', 'tier_to' => null, 'unit_price' => '17245'],
]);
check($G, 'config rounding scale 2 (mặc định): nước 30,5 m³ → 400.972,5', $w['total'] === 40097250, (string) $w['total']);

// =========================================================== TIER (A1, BR-13) - bậc lấy từ DB seed
$G = 'TIER';
$seedTariff = rows($pdo, 'SELECT * FROM tariffs WHERE id = 1')[0];
$seedTiers = (new App\Repositories\TariffRepository())->tiersOf(1);
$cases = ['0' => '0', '49.99' => null, '50' => '90300', '50.5' => '91233', '51' => '92166', '99.99' => null, '100' => '183600', '199.99' => null, '200' => '400300', '343' => '804350', '400' => '978200', '401' => '981351'];
foreach ($cases as $cl => $expected) {
    $r = TariffCalculator::calculate((int) Decimal::toCents((string) $cl), $seedTariff, $seedTiers);
    $ref = refTier(bcadd((string) $cl, '0', 2), BR13);
    $qty = array_sum(array_column($r['lines'], 'quantity'));
    $widthOk = true;
    foreach ($r['lines'] as $l) {
        $widthOk = $widthOk && ($l['tier_to'] === null || $l['quantity'] <= $l['tier_to'] - $l['tier_from']);
    }
    $ok = bccomp(Decimal::fromCents($r['subtotal']), $ref, 2) === 0 && ($expected === null || Decimal::fromCents($r['subtotal']) === $expected . '.00')
        && $qty === (int) Decimal::toCents((string) $cl) && $widthOk;
    check($G, "CL {$cl} kWh = {$ref} (không hở, không chồng, không +1)", $ok, Decimal::fromCents($r['subtotal']));
}
check($G, '49,999 không biểu diễn được (DECIMAL 2 số lẻ) → bị chặn từ đầu vào (Decimal::toCents = null)', Decimal::toCents('49.999') === null);
$r51 = TariffCalculator::calculate(5100, $seedTariff, $seedTiers);
check($G, '51 kWh = 50×1.806 + 1×1.866 (không +1)', $r51['subtotal'] === 9030000 + 186600 && count($r51['lines']) === 2 && $r51['lines'][1]['quantity'] === 100);
try {
    TariffCalculator::calculate(5000, $seedTariff, [['tier_order' => 1, 'tier_from' => '0', 'tier_to' => '50', 'unit_price' => '1806'], ['tier_order' => 2, 'tier_from' => '60', 'tier_to' => null, 'unit_price' => '1866']]);
    check($G, 'bậc hở (50 → 60) → không tính', false);
} catch (InvalidArgumentException $e) {
    check($G, 'bậc hở (50 → 60) → không tính', true);
}
$flat = TariffCalculator::calculate(15000, ['usage_type' => 'kinh_doanh', 'vat_percent' => '8.00', 'business_unit_price' => '3904.00'], $seedTiers);
check($G, 'kinh doanh: CL × đơn giá, 1 dòng [0, NULL), không áp bậc', $flat['subtotal'] === 58560000 && count($flat['lines']) === 1 && $flat['lines'][0]['tier_to'] === null && $flat['vat'] === 4684800);

// =========================================================== CONSUMPTION + BILLING (FN-05.01 - 05.04) trên kỳ 07/2024
$G = 'BILLING';
// Công tơ KD thứ 2 cho CC-2305 (căn 3): sinh hoạt + kinh doanh cùng căn (BR-06)
actAs(BQL, 'bql');
$m9 = (new MeterService())->create(['apartment_id' => '3', 'meter_code' => 'CC2305-DIEN-KD', 'utility_type' => 'dien', 'usage_type' => 'kinh_doanh', 'display_label' => 'KD', 'initial_reading' => '1000']);
(new ParkingVehicleService())->update(3, ['quantity_xe_dap_dien' => '3', 'quantity_xe_may' => '2', 'quantity_o_to' => '1']);

$rd = [];
foreach ([1 => '1686', 2 => '232', 3 => '800', 4 => '160', 6 => '74', 7 => '0', 8 => '0'] as $meter => $csc) {
    $rd[$meter] = enter($meter, $csc);
}
$rd[5] = enter(5, '600', false); // CC-2305 điện còn Nháp
$rd[9] = enter((int) $m9, '1100');

actAs(KTO, 'ke_toan');
$sum = $calc->calculatePeriod(P2);
check($G, 'tính cả kỳ: 3 căn tạo mới, CC-2305 lỗi vì chỉ số Nháp (BR-11), không tạo bản tính',
    count($sum['created']) === 3 && isset($sum['errors']['3']) && str_contains(implode(' ', $sum['errors']['3']), 'Nháp') && chargeOf($pdo, 3) === [],
    json_encode($sum, JSON_UNESCAPED_UNICODE));

$bt1 = chargeOf($pdo, 1);
check($G, 'REGRESSION 343 kWh (DB): tiền điện 804.350, VAT 64.348, tổng điện 868.698',
    $bt1['electricity_amount'] === '804350.00' && $bt1['electricity_vat_amount'] === '64348.00'
    && bcadd($bt1['electricity_amount'], $bt1['electricity_vat_amount'], 2) === '868698.00', json_encode($bt1));
$lines = array_column(rows($pdo, "SELECT amount FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = 'dien' ORDER BY tier_order", [$bt1['id']]), 'amount');
check($G, 'REGRESSION 343 kWh (DB breakdown): 90.300 / 93.300 / 216.700 / 272.900 / 131.150, meter_reading_id đúng',
    $lines === ['90300.00', '93300.00', '216700.00', '272900.00', '131150.00']
    && (int) q($pdo, "SELECT COUNT(DISTINCT meter_reading_id) FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = 'dien'", [$bt1['id']]) === 1
    && (int) q($pdo, "SELECT meter_reading_id FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = 'dien' LIMIT 1", [$bt1['id']]) === $rd[1],
    json_encode($lines));
check($G, 'REGRESSION nước 16 m³ = 185.120 (không tách VAT - ASSUMPTION J-07), breakdown 110.450 + 74.670',
    $bt1['water_amount'] === '185120.00'
    && array_column(rows($pdo, "SELECT amount FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = 'nuoc' ORDER BY tier_order", [$bt1['id']]), 'amount') === ['110450.00', '74670.00']);
check($G, 'phí: DV 80 m² × 3.000 = 240.000; BHCN 80 × 1.953 = 156.240 (J-08); 1 ô tô 500.000',
    $bt1['service_fee_amount'] === '240000.00' && $bt1['fire_insurance_amount'] === '156240.00' && $bt1['parking_fee_amount'] === '500000.00');
check($G, 'tổng phát sinh BT1 = 1.950.058 (khớp phiếu 06/2024), nợ cũ 0, tổng phải thu = phát sinh, trạng thái Đã tính',
    $bt1['total_arising'] === '1950058.00' && $bt1['old_debt_amount'] === '0.00' && $bt1['total_due'] === '1950058.00' && $bt1['status'] === 'da_tinh');

$s01 = chargeOf($pdo, 2);
check($G, 'điện KINH DOANH: 150 × 3.904 = 585.600, VAT 46.848; 1 dòng bậc [0, NULL)',
    $s01['electricity_amount'] === '585600.00' && $s01['electricity_vat_amount'] === '46848.00'
    && rows($pdo, "SELECT tier_order, tier_from, tier_to, quantity, unit_price FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = 'dien'", [$s01['id']])
       == [['tier_order' => 1, 'tier_from' => '0.00', 'tier_to' => null, 'quantity' => '150.00', 'unit_price' => '3904.00']]);
check($G, 'nước KINH DOANH: 30 × 25.160 = 754.800, không áp bậc sinh hoạt',
    $s01['water_amount'] === '754800.00' && (int) q($pdo, "SELECT COUNT(*) FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = 'nuoc'", [$s01['id']]) === 1);
check($G, 'S01: 2 xe máy = 100.000; tổng 1.784.428', $s01['parking_fee_amount'] === '100000.00' && $s01['total_arising'] === '1784428.00');

$cc6 = chargeOf($pdo, 4);
check($G, 'CL = 0 (CC-2306): tiền điện/nước 0, không có dòng bậc; chỉ phí cố định: DV 70 × 1.500 (khong_phat_sinh, J-16) + BHCN = 241.710',
    $cc6['electricity_amount'] === '0.00' && $cc6['water_amount'] === '0.00' && $cc6['service_fee_amount'] === '105000.00'
    && $cc6['total_arising'] === '241710.00' && (int) q($pdo, 'SELECT COUNT(*) FROM charge_tier_details WHERE apartment_charge_id = ?', [$cc6['id']]) === 0);

approveReading($rd[5]);
actAs(KTO, 'ke_toan');
$r = expectOk($G, 'CC-2305 sau khi duyệt chỉ số → tính được', fn () => $calc->calculateApartment(P2, 3));
$cc5 = chargeOf($pdo, 3);
check($G, 'nhiều công tơ (SH 150 kWh + KD 100 kWh cùng căn): tiền điện 291.950 + 390.400 = 682.350; VAT 23.356 + 31.232 = 54.588',
    $cc5['electricity_consumption'] === '250.00' && $cc5['electricity_amount'] === '682350.00' && $cc5['electricity_vat_amount'] === '54588.00');
check($G, 'nhiều công tơ: chi tiết bậc gắn đúng 2 meter_reading_id (giải trình theo công tơ)',
    array_map('intval', array_column(rows($pdo, "SELECT DISTINCT meter_reading_id FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = 'dien' ORDER BY meter_reading_id", [$cc5['id']]), 'meter_reading_id')) === [$rd[5], $rd[9]]);
check($G, 'gửi xe nhiều loại: 3 xe đạp/điện × 100.000 + 2 xe máy × 50.000 + 1 ô tô × 500.000 = 900.000', $cc5['parking_fee_amount'] === '900000.00');
check($G, 'CC-2305 tổng phát sinh = 2.118.988 (= Σ breakdown)', $cc5['total_arising'] === '2118988.00');
check($G, 'mọi bản tính: Σ bậc = cột tiền điện/nước', tierSumMatches($pdo, $bt1) && tierSumMatches($pdo, $s01) && tierSumMatches($pdo, $cc5) && tierSumMatches($pdo, $cc6));
check($G, 'audit CALCULATE_APARTMENT_CHARGE (snapshot) + CALCULATE_BILLING_PERIOD (tóm tắt lô)',
    auditCount($pdo, 'CALCULATE_APARTMENT_CHARGE', 'apartment_charge', (int) $bt1['id']) === 1 && auditCount($pdo, 'CALCULATE_BILLING_PERIOD', 'billing_period', P2) === 1);

// CL lớn
$big = TariffCalculator::calculate(100000000, $seedTariff, $seedTiers); // 1.000.000 kWh
check($G, 'CL lớn 1.000.000 kWh: chính xác theo hàm tham chiếu', Decimal::fromCents($big['subtotal']) === bcadd(refTier('1000000.00', BR13), '0', 2));
// công tơ chưa có chỉ số
actAs(BQL, 'bql');
$m10 = (int) (new MeterService())->create(['apartment_id' => '2', 'meter_code' => 'S01-NUOC-2', 'utility_type' => 'nuoc', 'usage_type' => 'sinh_hoat', 'display_label' => 'T2', 'initial_reading' => '0']);
actAs(KTO, 'ke_toan');
expectError($G, 'công tơ đang hoạt động chưa có chỉ số → không tính căn (BR-11)', 'apartment', fn () => $calc->calculateApartment(P2, 2), 'chưa có chỉ số');
check($G, '… bản tính cũ S01 không đổi và bị đánh dấu CẦN TÍNH LẠI (stale_error)', chargeOf($pdo, 2)['total_arising'] === '1784428.00' && state(2) === 'stale_error');
actAs(BQL, 'bql');
(new MeterService())->deactivate($m10);
check($G, 'ngừng công tơ chưa ghi số → bản tính S01 trở lại hiện hành', state(2) === 'current');
// CL bị sửa tay trong DB
$pdo->exec('UPDATE meter_readings SET consumption = consumption + 1 WHERE id = ' . $rd[7]);
actAs(KTO, 'ke_toan');
expectError($G, 'CL lưu ≠ CSC − CSĐ (sửa tay DB) → không tính, báo không nhất quán', 'apartment', fn () => $calc->calculateApartment(P2, 4), 'không nhất quán');
$pdo->exec('UPDATE meter_readings SET consumption = consumption - 1 WHERE id = ' . $rd[7]);

// =========================================================== FIXED FEE (FN-05.03)
$G = 'FIXED_FEE';
$rates = ['dich_vu_co_o' => ['id' => 1, 'unit_price' => '3000.00', 'effective_date' => '2024-01-01'], 'dich_vu_khong_o' => ['id' => 2, 'unit_price' => '1500.00', 'effective_date' => '2024-01-01'],
    'bao_hiem_chay_no' => ['id' => 3, 'unit_price' => '1953.00', 'effective_date' => '2024-01-01'], 'gui_xe_dap_dien' => ['id' => 4, 'unit_price' => '100000.00', 'effective_date' => '2024-01-01'],
    'gui_xe_may' => ['id' => 5, 'unit_price' => '50000.00', 'effective_date' => '2024-01-01'], 'gui_o_to' => ['id' => 6, 'unit_price' => '500000.00', 'effective_date' => '2024-01-01']];
$f = FixedFeeCalculator::calculate(['area' => '80.50', 'occupancy_status' => 'co_o'], [], $rates);
check($G, 'phí theo m² (DT lẻ 80,5): DV 241.500, BHCN 157.216,5; không xe → không có dòng gửi xe',
    array_column($f['lines'], 'amount', 'fee_type') === ['dich_vu_co_o' => 24150000, 'bao_hiem_chay_no' => 15721650] && $f['errors'] === []);
$f = FixedFeeCalculator::calculate(['area' => '0', 'occupancy_status' => 'co_o'], ['xe_may' => 1], $rates);
check($G, 'VAL-05.02: DT = 0 → lỗi dữ liệu, không tính phí m²', $f['errors'] !== [] && str_contains($f['errors'][0], 'VAL-05.02'));
$f = FixedFeeCalculator::calculate(['area' => '70', 'occupancy_status' => 'co_o'], ['o_to' => 1], array_merge($rates, ['gui_o_to' => null]));
check($G, 'thiếu đơn giá hiệu lực (gửi ô tô) → lỗi VAL-05.01, không bỏ qua im lặng', $f['errors'] !== [] && str_contains($f['errors'][0], 'VAL-05.01'));
$f = FixedFeeCalculator::calculate(['area' => '70', 'occupancy_status' => 'co_o'], ['o_to' => 0, 'xe_may' => 0], $rates);
check($G, 'gửi xe 0 xe → 0 đ, không dòng', array_filter($f['lines'], static fn ($l) => $l['group'] === 'parking') === []);
check($G, 'phí mở lại dịch vụ: NOT CALCULATED (BLOCKED J-09) và không cộng vào tổng',
    in_array('mo_lai_dich_vu', array_column($f['not_calculated'], 'fee_type'), true) && !in_array('mo_lai_dich_vu', array_column($f['lines'], 'fee_type'), true));
Config::set('billing.fire_insurance', ['enabled' => false, 'fee_type' => 'bao_hiem_chay_no']);
$f = FixedFeeCalculator::calculate(['area' => '70', 'occupancy_status' => 'co_o'], [], $rates);
check($G, 'BHCN tắt bằng config (J-08) → không tính, ghi NOT CALCULATED', !in_array('bao_hiem_chay_no', array_column($f['lines'], 'fee_type'), true)
    && in_array('bao_hiem_chay_no', array_column($f['not_calculated'], 'fee_type'), true));
$preview = $calc->compute($periods->find(P2), (new App\Repositories\ApartmentRepository())->find(1), 0);
check($G, '… tính thử BT1 khi BHCN tắt: tổng giảm đúng 156.240 → bản đang lưu bị coi là CẦN TÍNH LẠI', $preview['columns']['total_arising'] === 195005800 - 15624000 && state(1) === 'stale');
Config::set('billing.fire_insurance', ['enabled' => true, 'fee_type' => 'bao_hiem_chay_no']);
check($G, 'bật lại BHCN → BT1 hiện hành', state(1) === 'current');
$fees = new App\Repositories\FixedFeeRepository();
actAs(BQL, 'bql');
$newDv = (new App\Services\FixedFeeService())->create(['fee_type' => 'dich_vu_co_o', 'unit_price' => '3500', 'effective_date' => '2024-08-01']);
check($G, 'đơn giá hiệu lực sau ngày kết thúc kỳ (01/08) KHÔNG áp cho kỳ 07/2024 [J-15]', (int) $fees->effectiveAt('dich_vu_co_o', '2024-07-31')['id'] !== (int) $newDv && state(1) === 'current');
check($G, '… nhưng áp cho kỳ sau (01/08 ≤ 31/08)', (int) $fees->effectiveAt('dich_vu_co_o', '2024-08-31')['id'] === (int) $newDv);
$warn = $calc->compute($periods->find(P2), array_merge((new App\Repositories\ApartmentRepository())->find(1), ['occupancy_status' => 'khong_phat_sinh']), 0);
check($G, 'J-16: căn khai báo "không phát sinh" nhưng có CL > 0 → cảnh báo, phí DV vẫn theo khai báo (1.500)', $warn['warnings'] !== [] && $warn['columns']['service_fee_amount'] === 12000000);

// =========================================================== TOTAL (FN-05.04)
$G = 'TOTAL';
$a = ChargeAggregationService::aggregate([['utility_type' => 'dien', 'consumption' => 34300, 'subtotal' => 80435000, 'vat' => 6434800]], [], 0);
check($G, 'chỉ tiền tiện ích: tổng = điện + VAT', $a['totals']['utility_charge'] === 86869800 && $a['totals']['fixed_fee'] === 0 && $a['columns']['total_due'] === 86869800);
$a = ChargeAggregationService::aggregate([], [['group' => 'service', 'amount' => 10500000], ['group' => 'fire_insurance', 'amount' => 13671000]], 0);
check($G, 'chỉ phí cố định', $a['totals']['utility_charge'] === 0 && $a['columns']['total_arising'] === 24171000);
$a = ChargeAggregationService::aggregate([['utility_type' => 'nuoc', 'consumption' => 1600, 'subtotal' => 18512000, 'vat' => 100]], [], 0);
check($G, 'DESIGN GAP J-07: nước có VAT ≠ 0 → lỗi, không cộng ngầm', $a['errors'] !== [] && str_contains($a['errors'][0], 'J-07'));
$a = ChargeAggregationService::aggregate([['utility_type' => 'dien', 'consumption' => 34300, 'subtotal' => 80435000, 'vat' => 6434800]], [['group' => 'parking', 'amount' => 50000000]], 10000000);
check($G, 'kết hợp + nợ cũ > 0: tổng phải thu = phát sinh + nợ cũ (BR-18)', $a['columns']['total_due'] === 86869800 + 50000000 + 10000000 && $a['totals']['old_debt'] === 10000000);
actAs(KTO, 'ke_toan');
expectError($G, 'nợ cũ âm → lỗi', 'old_debt_amount', fn () => $confirm->updateOldDebt((int) $bt1['id'], '-1', 'x', token((int) $bt1['id'])));
expectError($G, 'nợ cũ không phải số → lỗi', 'old_debt_amount', fn () => $confirm->updateOldDebt((int) $bt1['id'], 'abc', 'x', token((int) $bt1['id'])));
expectError($G, 'nợ cũ thiếu lý do → lỗi', 'reason', fn () => $confirm->updateOldDebt((int) $bt1['id'], '100000', '  ', token((int) $bt1['id'])));
expectError($G, 'nợ cũ với token cũ → từ chối', '_', fn () => $confirm->updateOldDebt((int) $bt1['id'], '100000', 'chứng từ', 'stale'), 'thay đổi');
expectOk($G, 'nợ cũ 100.000 (A-15, lý do) → lưu', fn () => $confirm->updateOldDebt((int) $bt1['id'], '100000', 'Phiếu nợ T6 số 12', token((int) $bt1['id'])));
$bt1 = chargeOf($pdo, 1);
check($G, 'tổng phải thu = 1.950.058 + 100.000 = 2.050.058; audit UPDATE_OLD_DEBT kèm lý do',
    $bt1['total_due'] === '2050058.00' && $bt1['old_debt_amount'] === '100000.00' && (lastAudit($pdo, 'UPDATE_OLD_DEBT', 'apartment_charge', (int) $bt1['id'])['new']['reason'] ?? '') === 'Phiếu nợ T6 số 12');
check($G, 'tính lại sau khi nhập nợ cũ → không đổi (nợ cũ giữ nguyên, idempotent)', $calc->calculateApartment(P2, 1)['action'] === 'unchanged' && chargeOf($pdo, 1)['old_debt_amount'] === '100000.00');
check($G, 'mọi bản tính kỳ: total_arising = Σ khoản; total_due = total_arising + nợ cũ (SQL)',
    (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE total_arising <> electricity_amount + electricity_vat_amount + water_amount + service_fee_amount + fire_insurance_amount + parking_fee_amount OR total_due <> total_arising + old_debt_amount') === 0);

// =========================================================== IDEMPOTENCY
$G = 'IDEMPOTENT';
$beforeTiers = (int) q($pdo, 'SELECT COUNT(*) FROM charge_tier_details');
$beforeAudit = (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE action IN ('CALCULATE_APARTMENT_CHARGE','RECALCULATE_APARTMENT_CHARGE')");
$again = $calc->calculatePeriod(P2);
check($G, 'bấm "Tính phí" lần 2: 4 căn không đổi, không sinh bản tính / dòng bậc / audit trùng',
    count($again['unchanged']) === 4 && $again['created'] === [] && $again['recalculated'] === []
    && (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = ?', [P2]) === 4
    && (int) q($pdo, 'SELECT COUNT(*) FROM charge_tier_details') === $beforeTiers
    && (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE action IN ('CALCULATE_APARTMENT_CHARGE','RECALCULATE_APARTMENT_CHARGE')") === $beforeAudit);

// =========================================================== CONFIRM (FN-05.06)
$G = 'CONFIRM';
$s01Id = (int) $s01['id'];
$cc5Id = (int) $cc5['id'];
$r = $confirm->confirm(P2, [(int) $bt1['id'] => token((int) $bt1['id'])]);
check($G, 'Kế toán xác nhận → Đã xác nhận, confirmed_by, audit CONFIRM_APARTMENT_CHARGE',
    $r['done'] === [(int) $bt1['id']] && chargeOf($pdo, 1)['status'] === 'da_xac_nhan' && (int) chargeOf($pdo, 1)['confirmed_by'] === KTO
    && auditCount($pdo, 'CONFIRM_APARTMENT_CHARGE', 'apartment_charge', (int) $bt1['id']) === 1);
$r = $confirm->confirm(P2, [(int) $bt1['id'] => token((int) $bt1['id'])]);
check($G, 'xác nhận lần 2 → bỏ qua "Đã được xử lý trước đó"', $r['done'] === [] && str_contains($r['skipped'][(int) $bt1['id']] ?? '', 'Đã được xử lý'));
$r = $confirm->confirm(P2, [$s01Id => 'token-cu']);
check($G, 'token cũ → bỏ qua', $r['done'] === [] && str_contains($r['skipped'][$s01Id] ?? '', 'thay đổi'));
expectError($G, 'trả lại thiếu lý do → lỗi', 'rejection_reason', fn () => $confirm->returnCharge($s01Id, '  ', token($s01Id)));
expectOk($G, 'trả lại kèm lý do → Bị trả lại', fn () => $confirm->returnCharge($s01Id, 'CL nước KD bất thường, đề nghị kiểm tra', token($s01Id)));
check($G, '… trạng thái bi_tra_lai, lý do lưu, audit RETURN_APARTMENT_CHARGE',
    chargeOf($pdo, 2)['status'] === 'bi_tra_lai' && chargeOf($pdo, 2)['rejection_reason'] === 'CL nước KD bất thường, đề nghị kiểm tra'
    && auditCount($pdo, 'RETURN_APARTMENT_CHARGE', 'apartment_charge', $s01Id) === 1);
$r = $confirm->confirm(P2, [$s01Id => token($s01Id)]);
check($G, 'không xác nhận được bản Bị trả lại', $r['done'] === []);
check($G, 'tính lại bản Bị trả lại → Đã tính (G6), xóa lý do', $calc->calculateApartment(P2, 2)['action'] === 'recalculated' && chargeOf($pdo, 2)['status'] === 'da_tinh' && chargeOf($pdo, 2)['rejection_reason'] === null);
actAs(BQL, 'bql');
$r = $confirm->approve(P2, [(int) $bt1['id'] => token((int) $bt1['id']), $s01Id => token($s01Id)]);
check($G, 'BQL duyệt (tùy chọn J-18): bản Đã xác nhận → Đã duyệt; bản Đã tính bị bỏ qua',
    $r['done'] === [(int) $bt1['id']] && isset($r['skipped'][$s01Id]) && chargeOf($pdo, 1)['status'] === 'da_duyet'
    && auditCount($pdo, 'APPROVE_APARTMENT_CHARGE', 'apartment_charge', (int) $bt1['id']) === 1);

// =========================================================== RECALC sau khi sửa chỉ số (FN-04.07) + P19
$G = 'RECALC';
actAs(KT, 'ky_thuat');
$readingSvc->update($rd[2], ['current_reading' => '233', 'note' => 'Đọc lại công tơ nước', 'version_token' => MeterReadingService::versionToken($readingSvc->find($rd[2]))]);
check($G, 'sửa chỉ số nước BT1 (đã duyệt → Nháp) → bản tính BT1 CẦN TÍNH LẠI (stale_error), tiền cũ không được coi là hiện hành', state(1) === 'stale_error');
actAs(KTO, 'ke_toan');
expectError($G, '… tính lại khi chỉ số còn Nháp → từ chối (BR-11), giữ bản cũ', 'apartment', fn () => $calc->calculateApartment(P2, 1), 'Nháp');
approveReading($rd[2]);
check($G, 'BQL duyệt lại chỉ số → CẦN TÍNH LẠI (stale) với lý do tiền nước thay đổi', state(1) === 'stale');
actAs(KTO, 'ke_toan');
$r = $calc->calculateApartment(P2, 1);
$bt1 = chargeOf($pdo, 1);
$rec = lastAudit($pdo, 'RECALCULATE_APARTMENT_CHARGE', 'apartment_charge', (int) $bt1['id']);
check($G, 'tính lại → cập nhật tại chỗ (cùng id), nước 17 m³ = 197.565, về Đã tính, xóa xác nhận/duyệt, giữ nợ cũ',
    $r['action'] === 'recalculated' && (int) $r['charge_id'] === (int) $bt1['id'] && $bt1['water_amount'] === '197565.00' && $bt1['status'] === 'da_tinh'
    && $bt1['confirmed_at'] === null && $bt1['approved_at'] === null && $bt1['old_debt_amount'] === '100000.00' && tierSumMatches($pdo, $bt1));
check($G, 'audit RECALCULATE_APARTMENT_CHARGE: trạng thái cũ Đã duyệt, lý do thay đổi, snapshot mới',
    ($rec['old']['status'] ?? '') === 'da_duyet' && $rec['new']['reasons'] !== [] && isset($rec['new']['snapshot']['meters']));

actAs(BQL, 'bql');
$tariffs = new TariffService();
$sameTiers = array_map(static fn ($t) => ['tier_from' => $t[0], 'tier_to' => (string) $t[1], 'unit_price' => $t[2]], BR13);
$tSame = $tariffs->create(['utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'vat_percent' => '8', 'business_unit_price' => '', 'effective_date' => '2024-07-20', 'tiers' => $sameTiers]);
$c = $calc->consistency($periods->find(P2), chargeOf($pdo, 1));
check($G, 'P19: phiên bản biểu giá mới (cùng giá, hiệu lực 20/07 trong kỳ) → CẦN TÍNH LẠI vì đổi phiên bản, số tiền không đổi',
    $c['state'] === 'stale' && count($c['reasons']) === 1 && str_contains($c['reasons'][0], 'P19'), json_encode($c['reasons'], JSON_UNESCAPED_UNICODE));
$tNew = array_map(static fn ($t) => ['tier_from' => $t[0], 'tier_to' => (string) $t[1], 'unit_price' => $t[0] === '0' ? '1900' : $t[2]], BR13);
$tUp = $tariffs->create(['utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'vat_percent' => '8', 'business_unit_price' => '', 'effective_date' => '2024-07-25', 'tiers' => $tNew]);
actAs(KTO, 'ke_toan');
$r = $confirm->confirm(P2, [(int) $bt1['id'] => token((int) $bt1['id'])]);
check($G, 'P19: xác nhận bản tính cũ sau khi đổi giá → bị chặn "CẦN TÍNH LẠI"', $r['done'] === [] && str_contains($r['skipped'][(int) $bt1['id']] ?? '', 'CẦN TÍNH LẠI'));
$all = $calc->calculatePeriod(P2);
$bt1 = chargeOf($pdo, 1);
check($G, 'tính lại cả kỳ: áp biểu giá hiệu lực tại ngày kết thúc kỳ (25/07, bậc 1 = 1.900) [ASSUMPTION J-15]: điện 809.050, VAT 64.724',
    $bt1['electricity_amount'] === '809050.00' && $bt1['electricity_vat_amount'] === '64724.00', json_encode($all, JSON_UNESCAPED_UNICODE));
check($G, '… căn điện SH khác cũng tính lại; căn chỉ KD (S01) không đổi', in_array((int) $cc5Id, $all['recalculated'], true) && in_array($s01Id, $all['unchanged'], true));

// =========================================================== LOCK (FN-05.07)
$G = 'LOCK';
actAs(BQL, 'bql');
$newApt = (new ApartmentService())->create(['building_id' => '1', 'zone_id' => '3', 'code' => 'CC-2307', 'area' => '55.5', 'occupancy_status' => 'co_o']);
$pdo->exec("INSERT INTO import_batches (billing_period_id, file_name, status, uploaded_by) VALUES (2, 'pending.xlsx', 'da_kiem_tra', 2)");
$pendingBatch = (int) $pdo->lastInsertId();
$draftMeter = (int) (new MeterService())->create(['apartment_id' => (string) $newApt, 'meter_code' => 'CC2307-DIEN', 'utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '0']);
$draftReading = enter($draftMeter, '20', false);
actAs(KTO, 'ke_toan');
$blockers = $closing->lockBlockers(P2);
$text = implode(' | ', $blockers);
check($G, 'chặn khóa khi còn chỉ số Nháp', str_contains($text, 'Nháp'), $text);
check($G, 'chặn khóa khi còn lô import chờ', str_contains($text, 'lô import'), $text);
check($G, 'chặn khóa khi căn chưa có bản tính', str_contains($text, 'chưa có bản tính') && str_contains($text, 'CC-2307'), $text);
check($G, 'chặn khóa khi bản tính chưa xác nhận', str_contains($text, 'chưa xác nhận'), $text);
expectError($G, 'lock() trả danh sách lý do, kỳ vẫn mở', 'lock', fn () => $closing->lock(P2));
check($G, '… kỳ không đổi trạng thái, không audit LOCK', $periods->find(P2)['status'] === 'mo' && auditCount($pdo, 'LOCK_BILLING_PERIOD', 'billing_period', P2) === 0);

$pdo->exec("UPDATE import_batches SET status = 'da_huy' WHERE id = {$pendingBatch}");
approveReading($draftReading);
actAs(KTO, 'ke_toan');
$calc->calculatePeriod(P2);
$cc7 = chargeOf($pdo, (int) $newApt);
check($G, 'căn mới (1 công tơ SH 20 kWh): 20 × 1.900 = 38.000 + VAT 3.040; DV 55,5 × 3.000; BHCN 55,5 × 1.953 = 108.391,5',
    $cc7['electricity_amount'] === '38000.00' && $cc7['electricity_vat_amount'] === '3040.00' && $cc7['service_fee_amount'] === '166500.00' && $cc7['fire_insurance_amount'] === '108391.50');
// bản tính stale (đổi diện tích) phải chặn khoá
actAs(BQL, 'bql');
$apSvc = new ApartmentService();
$cur = $apSvc->find(4);
$apSvc->update(4, ['building_id' => '1', 'zone_id' => '3', 'code' => 'CC-2306', 'area' => '71', 'occupancy_status' => 'khong_phat_sinh', 'updated_at' => $cur['updated_at']]);
confirmAll();
actAs(KTO, 'ke_toan');
$text = implode(' | ', $closing->lockBlockers(P2));
check($G, 'chặn khóa khi bản tính CẦN TÍNH LẠI (diện tích CC-2306 đổi sau khi tính)', str_contains($text, 'CẦN TÍNH LẠI') && str_contains($text, 'CC-2306'), $text);
$calc->calculatePeriod(P2);
confirmAll();
actAs(KTO, 'ke_toan');
check($G, 'đủ điều kiện → không còn lý do chặn', $closing->lockBlockers(P2) === [], implode(' | ', $closing->lockBlockers(P2)));
$lockedTotals = rows($pdo, 'SELECT id, apartment_id, total_due, service_fee_amount, parking_fee_amount FROM apartment_charges WHERE billing_period_id = ? ORDER BY id', [P2]);
expectOk($G, 'khóa kỳ', fn () => $closing->lock(P2));
$p = $periods->find(P2);
$la = lastAudit($pdo, 'LOCK_BILLING_PERIOD', 'billing_period', P2);
check($G, 'kỳ Đã khóa, locked_by/locked_at; audit LOCK_BILLING_PERIOD (cũ mo → mới da_khoa)',
    $p['status'] === 'da_khoa' && (int) $p['locked_by'] === KTO && $p['locked_at'] !== null && ($la['old']['status'] ?? '') === 'mo' && ($la['new']['status'] ?? '') === 'da_khoa');
expectError($G, 'khóa lần 2 → lỗi "đã được khóa"', '_', fn () => $closing->lock(P2), 'đã được khóa');

// Sau khoá: mọi thao tác thay đổi bị chặn ở tầng Service (bypass UI/route)
$G = 'LOCK_EFFECT';
actAs(KT, 'ky_thuat');
actAs(BQL, 'bql');
$lateMeter = (int) (new MeterService())->create(['apartment_id' => '1', 'meter_code' => 'BT1-NUOC-2', 'utility_type' => 'nuoc', 'usage_type' => 'sinh_hoat', 'display_label' => 'T2', 'initial_reading' => '0']);
actAs(KT, 'ky_thuat');
expectError($G, 'tạo chỉ số', '_', fn () => $readingSvc->create(P2, $lateMeter, ['current_reading' => '5', 'note' => '']), 'khóa');
actAs(BQL, 'bql');
(new MeterService())->deactivate($lateMeter); // công tơ thử, không có chỉ số -> ngừng để không chặn các bước sau
actAs(KT, 'ky_thuat');
expectError($G, 'sửa chỉ số', '_', fn () => $readingSvc->update($rd[1], ['current_reading' => '1700', 'note' => 'x', 'version_token' => MeterReadingService::versionToken($readingSvc->find($rd[1]))]), 'khóa');
expectError($G, 'import chỉ số', '_', fn () => (new ReadingImportService())->upload(P2, null), 'khóa');
actAs(BQL, 'bql');
expectError($G, 'duyệt chỉ số', '_', fn () => $approval->approve(P2, [$rd[1] => 'x']), 'khóa');
actAs(KTO, 'ke_toan');
expectError($G, 'tính / tính lại phí', '_', fn () => $calc->calculateApartment(P2, 1), 'khóa');
expectError($G, 'tính phí cả kỳ', '_', fn () => $calc->calculatePeriod(P2), 'khóa');
expectError($G, 'xác nhận bản tính', '_', fn () => $confirm->confirm(P2, [(int) $bt1['id'] => token((int) $bt1['id'])]), 'khóa');
expectError($G, 'trả lại bản tính', '_', fn () => $confirm->returnCharge((int) $bt1['id'], 'x', token((int) $bt1['id'])), 'khóa');
expectError($G, 'sửa nợ cũ', '_', fn () => $confirm->updateOldDebt((int) $bt1['id'], '1', 'x', token((int) $bt1['id'])), 'khóa');
actAs(BQL, 'bql');
expectError($G, 'BQL duyệt bản tính', '_', fn () => $confirm->approve(P2, [(int) $bt1['id'] => token((int) $bt1['id'])]), 'khóa');
expectError($G, 'biểu giá mới hiệu lực trong kỳ đã khóa (FN-03 VAL-03.04) → từ chối', 'effective_date', fn () => $tariffs->create(['utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'vat_percent' => '8', 'business_unit_price' => '', 'effective_date' => '2024-07-31', 'tiers' => $sameTiers]));
// FN-02 đổi diện tích / số xe sau khoá -> lịch sử kỳ không đổi
$cur = $apSvc->find(1);
$apSvc->update(1, ['building_id' => '1', 'zone_id' => '1', 'code' => 'BT1', 'area' => '90', 'occupancy_status' => 'co_o', 'updated_at' => $cur['updated_at']]);
(new ParkingVehicleService())->update(1, ['quantity_xe_dap_dien' => '0', 'quantity_xe_may' => '0', 'quantity_o_to' => '2']);
$after = rows($pdo, 'SELECT id, apartment_id, total_due, service_fee_amount, parking_fee_amount FROM apartment_charges WHERE billing_period_id = ? ORDER BY id', [P2]);
$d = $calc->detail((int) $bt1['id']);
$dvSnap = array_values(array_filter($d['fees'], static fn ($x) => $x['fee_type'] === 'dich_vu_co_o'))[0] ?? [];
$carSnap = array_values(array_filter($d['fees'], static fn ($x) => $x['fee_type'] === 'gui_o_to'))[0] ?? [];
check($G, 'FN-02 đổi DT (80→90) và số ô tô (1→2) sau khóa: bản tính kỳ khóa giữ nguyên số tiền',
    $after === $lockedTotals && $d['consistency']['state'] === 'locked');
check($G, '… chi tiết hiển thị cơ sở tính lúc tính (snapshot: 80 m², 1 xe), khớp số tiền đang lưu',
    ($dvSnap['base_quantity'] ?? '') === '80.00' && ($carSnap['base_quantity'] ?? '') === '1.00' && $d['fee_check'] === true);

// =========================================================== REOPEN (FN-05.08)
$G = 'REOPEN';
actAs(BQL, 'bql');
expectError($G, 'thiếu lý do → lỗi', 'reopen_reason', fn () => $closing->reopen(P2, '   '));
expectError($G, 'lý do > 500 ký tự → lỗi', 'reopen_reason', fn () => $closing->reopen(P2, str_repeat('a', 501)));
$before = rows($pdo, 'SELECT id, status, total_due FROM apartment_charges WHERE billing_period_id = ? ORDER BY id', [P2]);
$warnings = expectOk($G, 'BQL mở lại có lý do', fn () => $closing->reopen(P2, 'Cư dân BT1 khiếu nại chỉ số điện'));
$p = $periods->find(P2);
$ra = lastAudit($pdo, 'REOPEN_BILLING_PERIOD', 'billing_period', P2);
check($G, 'kỳ → Mở lại; reopened_by/at, reopen_reason', $p['status'] === 'mo_lai' && (int) $p['reopened_by'] === BQL && $p['reopened_at'] !== null && $p['reopen_reason'] === 'Cư dân BT1 khiếu nại chỉ số điện');
check($G, 'audit REOPEN_BILLING_PERIOD: kỳ, lý do, người, thời điểm, trạng thái cũ/mới',
    ($ra['old']['status'] ?? '') === 'da_khoa' && ($ra['new']['status'] ?? '') === 'mo_lai' && ($ra['new']['reason'] ?? '') === 'Cư dân BT1 khiếu nại chỉ số điện'
    && (int) $ra['user_id'] === BQL && $ra['created_at'] !== null);
check($G, 'mở lại KHÔNG xóa/sửa bản tính cũ', rows($pdo, 'SELECT id, status, total_due FROM apartment_charges WHERE billing_period_id = ? ORDER BY id', [P2]) === $before);
check($G, 'không có kỳ sau có chỉ số → không có cảnh báo dây chuyền (J-19)', $warnings === []);
expectError($G, 'mở lại lần 2 (kỳ không còn Đã khóa) → lỗi', '_', fn () => $closing->reopen(P2, 'lần 2'), 'không ở trạng thái Đã khóa');
check($G, 'sau mở lại: bản tính BT1 CẦN TÍNH LẠI vì DT/xe đã đổi → chặn khóa lại', state(1) === 'stale' && str_contains(implode(' ', $closing->lockBlockers(P2)), 'CẦN TÍNH LẠI'));
actAs(KT, 'ky_thuat');
expectOk($G, 'sau mở lại: Kỹ thuật sửa được chỉ số', fn () => $readingSvc->update($rd[1], ['current_reading' => '1690', 'note' => 'Khiếu nại: đọc lại', 'version_token' => MeterReadingService::versionToken($readingSvc->find($rd[1]))]));
approveReading($rd[1]);
actAs(KTO, 'ke_toan');
$calc->calculatePeriod(P2);
$bt1 = chargeOf($pdo, 1);
check($G, 'tính lại sau mở lại: 347 kWh, DT 90 m², 2 ô tô; về Đã tính', $bt1['electricity_consumption'] === '347.00' && $bt1['service_fee_amount'] === '270000.00' && $bt1['parking_fee_amount'] === '1000000.00' && $bt1['status'] === 'da_tinh');
confirmAll();
actAs(KTO, 'ke_toan');
expectOk($G, 'khóa lại sau khi xác nhận lại (Mở lại → Đã khóa)', fn () => $closing->lock(P2));
check($G, '… audit LOCK lần 2: cũ mo_lai', (lastAudit($pdo, 'LOCK_BILLING_PERIOD', 'billing_period', P2)['old']['status'] ?? '') === 'mo_lai' && $periods->find(P2)['status'] === 'da_khoa');

// =========================================================== CONCURRENCY (tiến trình PHP thật)
$G = 'CONCURRENCY';
$out = race($testDb, [[BQL, 'bql', 'reopen', P2, 'race A'], [BQL, 'bql', 'reopen', P2, 'race B']]);
check($G, 'mở lại 2 lần đồng thời → đúng 1 thành công, 1 audit REOPEN mới', count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && auditCount($pdo, 'REOPEN_BILLING_PERIOD', 'billing_period', P2) === 2, json_encode($out, JSON_UNESCAPED_UNICODE));

actAs(BQL, 'bql');
(new ParkingVehicleService())->update(1, ['quantity_xe_dap_dien' => '1', 'quantity_xe_may' => '0', 'quantity_o_to' => '2']);
$out = race($testDb, [[KTO, 'ke_toan', 'calc', P2, 1], [KTO, 'ke_toan', 'calc', P2, 1]]);
$msgs = array_column($out, 'message');
sort($msgs);
check($G, '2 request tính cùng căn-kỳ → 1 "recalculated" + 1 "unchanged", vẫn 1 bản tính, dữ liệu đủ',
    $msgs === ['recalculated', 'unchanged'] && (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges WHERE apartment_id = 1 AND billing_period_id = ?', [P2]) === 1 && tierSumMatches($pdo, chargeOf($pdo, 1)),
    json_encode($out, JSON_UNESCAPED_UNICODE));

actAs(BQL, 'bql');
(new ParkingVehicleService())->update(1, ['quantity_xe_dap_dien' => '2', 'quantity_xe_may' => '0', 'quantity_o_to' => '2']);
$tok = MeterReadingService::versionToken($readingSvc->find($rd[1]));
$out = race($testDb, [[KT, 'ky_thuat', 'edit', $rd[1], '1691', $tok], [KTO, 'ke_toan', 'calc', P2, 1]]);
check($G, 'tính phí + sửa chỉ số đồng thời → không có "chỉ số mới + tiền cũ" hiện hành; dữ liệu không nửa vời',
    $out[0]['ok'] && readingOf($pdo, 1)['status'] === 'nhap' && state(1) === 'stale_error' && tierSumMatches($pdo, chargeOf($pdo, 1)), json_encode($out, JSON_UNESCAPED_UNICODE));
approveReading($rd[1]);

actAs(KTO, 'ke_toan');
$out = race($testDb, [[BQL, 'bql', 'tariff', '2024-07-28', '2000'], [KTO, 'ke_toan', 'calc', P2, 1]]);
$st = state(1);
check($G, 'tính phí + cập nhật biểu giá đồng thời → bản tính hoặc dùng giá mới (hiện hành) hoặc bị đánh dấu CẦN TÍNH LẠI; không âm thầm hiện hành với giá cũ',
    $out[0]['ok'] && in_array($st, ['current', 'stale'], true) && tierSumMatches($pdo, chargeOf($pdo, 1))
    && ($st !== 'current' || q($pdo, "SELECT unit_price FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = 'dien' AND tier_order = 1", [chargeOf($pdo, 1)['id']]) === '2000.00'),
    $st . ' ' . json_encode($out, JSON_UNESCAPED_UNICODE));
$calc->calculatePeriod(P2);
check($G, '… tính lại → hiện hành với bậc 1 = 2.000 (biểu giá 28/07)', state(1) === 'current'
    && q($pdo, "SELECT unit_price FROM charge_tier_details WHERE apartment_charge_id = ? AND utility_type = 'dien' AND tier_order = 1", [chargeOf($pdo, 1)['id']]) === '2000.00');

$bt1Id = (int) chargeOf($pdo, 1)['id'];
$confirmAudits = auditCount($pdo, 'CONFIRM_APARTMENT_CHARGE', 'apartment_charge', $bt1Id);
$out = race($testDb, [[KTO, 'ke_toan', 'confirm', P2, $bt1Id, token($bt1Id)], [KTO, 'ke_toan', 'confirm', P2, $bt1Id, token($bt1Id)]]);
check($G, 'Kế toán xác nhận 2 lần đồng thời → đúng 1 thành công, đúng 1 audit mới', count(array_filter($out, static fn ($o) => $o['ok'])) === 1
    && auditCount($pdo, 'CONFIRM_APARTMENT_CHARGE', 'apartment_charge', $bt1Id) === $confirmAudits + 1, json_encode($out, JSON_UNESCAPED_UNICODE));
confirmAll();

actAs(BQL, 'bql');
(new ParkingVehicleService())->update(1, ['quantity_xe_dap_dien' => '3', 'quantity_xe_may' => '0', 'quantity_o_to' => '2']); // BT1 thành CẦN TÍNH LẠI
$out = race($testDb, [[KTO, 'ke_toan', 'calc', P2, 1], [KTO, 'ke_toan', 'lock', P2]]);
check($G, 'khóa trong khi đang tính → khóa bị chặn (bản tính BT1 chưa hiện hành/chưa xác nhận ở mọi thứ tự), tính phí vẫn thành công',
    $out[0]['ok'] && !$out[1]['ok'] && $periods->find(P2)['status'] === 'mo_lai', json_encode($out, JSON_UNESCAPED_UNICODE));
confirmAll();
$out = race($testDb, [[KTO, 'ke_toan', 'lock', P2], [KTO, 'ke_toan', 'lock', P2]]);
check($G, 'khóa 2 lần đồng thời → đúng 1 thành công', count(array_filter($out, static fn ($o) => $o['ok'])) === 1 && $periods->find(P2)['status'] === 'da_khoa'
    && auditCount($pdo, 'LOCK_BILLING_PERIOD', 'billing_period', P2) === 3, json_encode($out, JSON_UNESCAPED_UNICODE));

// =========================================================== TRACEABILITY
$G = 'TRACE';
$d = $calc->detail($bt1Id);
$ok = true;
foreach ($d['meters'] as $m) {
    $r = rows($pdo, 'SELECT r.billing_period_id, m.apartment_id FROM meter_readings r JOIN meters m ON m.id = r.meter_id WHERE r.id = ?', [$m['reading']['meter_reading_id']])[0];
    $ok = $ok && (int) $r['billing_period_id'] === P2 && (int) $r['apartment_id'] === 1 && isset($m['snapshot']['tariff_id']);
}
check($G, 'bản tính → kỳ → căn → công tơ → chỉ số → CL → biểu giá (id) → bậc → tiền: mọi dòng truy được', $ok && count($d['meters']) === 2);
$versions = $d['snapshot']['versions'] ?? [];
check($G, 'snapshot có phiên bản biểu giá theo từng chỉ số + phiên bản đơn giá từng phí (DV, BHCN, gửi xe)',
    isset($versions['tariff:' . $rd[1]], $versions['tariff:' . $rd[2]], $versions['fee:dich_vu_co_o'], $versions['fee:bao_hiem_chay_no'], $versions['fee:gui_o_to'], $versions['fee:gui_xe_dap_dien']));
$meterTotal = 0;
foreach ($d['snapshot']['meters'] as $m) {
    $meterTotal += MoneyCalculator::fromDb($m['total']);
}
check($G, 'Σ thành tiền công tơ (snapshot) = tiền điện + VAT + tiền nước (cột)', $meterTotal === $d['columns']['electricity_amount'] + $d['columns']['electricity_vat_amount'] + $d['columns']['water_amount']);
check($G, 'snapshot tổng phải thu = cột total_due; kiểm tra số học tổng = breakdown', MoneyCalculator::fromDb($d['snapshot']['totals']['total_payable']) === $d['columns']['total_due'] && $d['totals']['consistent']);
$seedDetail = $calc->detail(1);
check($G, 'bản tính seed (chưa có snapshot) vẫn xem được: chi tiết bậc theo công tơ, phí theo khoản, không lỗi', $seedDetail['fees'] === null && count($seedDetail['meters']) === 2 && $seedDetail['consistency']['state'] === 'locked');

// =========================================================== COST ALLOCATION (FN-05.05)
$G = 'ALLOCATION';
$s = (new CostAllocationService())->summary(1, true);
check($G, 'kỳ 06/2024 theo tòa: 4 căn, tổng 4.773.552, TB/căn 1.193.388 (BR-35)',
    count($s['building']) === 1 && (int) $s['building'][0]['charged_apartments'] === 4 && $s['building'][0]['total_cost'] === '4773552.00' && $s['building'][0]['average_per_apartment'] === 119338800);
$zones = array_column($s['zone'], null, 'group_code');
check($G, 'theo khu: KHU-BT 1.950.058; KHU-SH 1.784.428; KHU-CC 1.039.066 (2 căn, TB 519.533)',
    $zones['KHU-BT']['total_cost'] === '1950058.00' && $zones['KHU-SH']['total_cost'] === '1784428.00' && $zones['KHU-CC']['total_cost'] === '1039066.00' && $zones['KHU-CC']['average_per_apartment'] === 51953300);
$zoneSum = array_sum(array_map(static fn ($z) => MoneyCalculator::fromDb($z['total_cost']), $s['zone']));
$aptSum = array_sum(array_map(static fn ($z) => MoneyCalculator::fromDb($z['total_cost']), $s['apartment']));
check($G, 'không nhân đôi: Σ khu = Σ căn = tổng tòa; số dòng căn = số bản tính', $zoneSum === 477355200 && $aptSum === 477355200 && count($s['apartment']) === 4);
check($G, 'CL tổng hợp: điện 643 kWh, nước 58 m³', $s['building'][0]['electricity_consumption'] === '643.00' && $s['building'][0]['water_consumption'] === '58.00');
$s2 = (new CostAllocationService())->summary(P2, true);
check($G, 'kỳ 07/2024 chỉ bản đã xác nhận: 5 căn', (int) $s2['building'][0]['charged_apartments'] === 5);

// =========================================================== AUDIT tổng
$G = 'AUDIT';
foreach (['CALCULATE_APARTMENT_CHARGE', 'RECALCULATE_APARTMENT_CHARGE', 'CONFIRM_APARTMENT_CHARGE', 'RETURN_APARTMENT_CHARGE', 'APPROVE_APARTMENT_CHARGE', 'UPDATE_OLD_DEBT', 'CALCULATE_BILLING_PERIOD', 'LOCK_BILLING_PERIOD', 'REOPEN_BILLING_PERIOD'] as $action) {
    check($G, "có audit {$action}", (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]) > 0);
}
check($G, 'kỳ khóa seed 06/2024 không bị đụng tới (4 bản tính phát hành giữ nguyên)', (int) q($pdo, "SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id = 1 AND status = 'da_phat_hanh'") === 4
    && q($pdo, 'SELECT SUM(total_due) FROM apartment_charges WHERE billing_period_id = 1') === '4773552.00');

// ===========================================================
$pass = 0;
$current = '';
foreach ($results as [$g, $n, $ok, $d]) {
    if ($g !== $current) {
        echo "\n[{$g}]\n";
        $current = $g;
    }
    echo ($ok ? '  PASS ' : '  FAIL ') . $n . ($ok || $d === '' ? '' : "  -> {$d}") . "\n";
    $pass += $ok ? 1 : 0;
}
$total = count($results);
echo "\n{$pass}/{$total} PASS\n";
exit($pass === $total ? 0 : 1);
