<?php

declare(strict_types=1);

/**
 * Fixture RIÊNG cho test HTTP chạy trên DB THẬT (Apache) - không phụ thuộc / không sửa dữ liệu người dùng.
 *
 * Mỗi lần chạy tạo 1 tòa nhà "E2E-<tag>" gồm 3 khu + 4 căn + 8 công tơ + xe, MÔ PHỎNG đúng seed (BT1, S01, CC-2305, CC-2306:
 * diện tích, khai báo phát sinh, loại sử dụng, xe) với mã có tiền tố <tag> (mã căn / công tơ duy nhất). Chỉ số khởi tạo
 * = CSC đã xác nhận 06/2024 của seed (1343, 216, 650, 130, 450, 62, 0, 0) → ở kỳ test mới, CSĐ = đúng số seed, nên số tiền
 * kỳ vọng của tài liệu (BT1 343 kWh = 804.350 + VAT 64.348 …) giữ nguyên mà KHÔNG cần chạm kỳ / chỉ số thật.
 *
 * Thao tác cấp KỲ (khoá kỳ cần MỌI công tơ đang hoạt động có chỉ số + mọi căn có bản tính đã xác nhận): e2e_fill_other_meters()
 * nhập chỉ số CL = 0 cho các công tơ KHÔNG thuộc fixture CHỈ TRONG KỲ TEST (bị xoá cùng kỳ). Công tơ thật bị chặn CSĐ (B7 - chỉ số
 * trước còn Nháp) → trả về danh sách để test báo "phụ thuộc dữ liệu thật" thay vì lỗi mơ hồ.
 *
 * Dọn dẹp: e2e_cleanup() chỉ xoá theo id fixture + id kỳ test (+ cảnh báo / mục tiêu / baseline gắn vào chúng). Audit giữ lại (BR-04).
 */

use App\Services\MeterReadingService;

require_once __DIR__ . '/legacy_compat.php'; // STEP 4C: dọn dẹp / truy vấn bảng v1 chạy được trên schema v2

// Chạy cùng test trên DB TẠM cài mới (tests/run_isolated.sh): E2E_DB = DB tạm, E2E_BASE = php -S. Mặc định: Apache + DB thật.
if ((string) getenv('E2E_DB') !== '') {
    if (getenv('E2E_DB') === 'energy_dss') {
        fwrite(STDERR, "E2E_DB phải là DB tạm.\n");
        exit(2);
    }
    $_ENV['DB_DATABASE'] = (string) getenv('E2E_DB');
    App\Core\Config::set('database.database', (string) getenv('E2E_DB'));
    App\Core\Config::set('app.url', (string) getenv('E2E_BASE'));
}
legacy_compat_install();

/** Định nghĩa seed được mô phỏng: khoá => [khu, mã, DT, khai báo] / [căn, tiện ích, sử dụng, chỉ số khởi tạo]. */
const E2E_SEED_ZONES = ['BT' => ['KHU-BT', 'Khu Biệt thự', 'biet_thu'], 'SH' => ['KHU-SH', 'Khu Shophouse', 'shophouse'], 'CC' => ['KHU-CC', 'Khu Chung cư', 'chung_cu']];
const E2E_SEED_APARTMENTS = [
    'BT1' => ['BT', 'BT1', '80.00', 'co_o'],
    'S01' => ['SH', 'S01', '60.00', 'co_o'],
    'CC-2305' => ['CC', 'CC-2305', '70.00', 'co_o'],
    'CC-2306' => ['CC', 'CC-2306', '70.00', 'khong_phat_sinh'],
];
/** Khoá công tơ = id công tơ seed tương ứng (1..8) để test cũ đổi tối thiểu. */
const E2E_SEED_METERS = [
    1 => ['BT1', 'dien', 'sinh_hoat', '1343.00', 'BT1-DIEN'],
    2 => ['BT1', 'nuoc', 'sinh_hoat', '216.00', 'BT1-NUOC'],
    3 => ['S01', 'dien', 'kinh_doanh', '650.00', 'S01-DIEN'],
    4 => ['S01', 'nuoc', 'kinh_doanh', '130.00', 'S01-NUOC'],
    5 => ['CC-2305', 'dien', 'sinh_hoat', '450.00', 'CC2305-DIEN'],
    6 => ['CC-2305', 'nuoc', 'sinh_hoat', '62.00', 'CC2305-NUOC'],
    7 => ['CC-2306', 'dien', 'sinh_hoat', '0.00', 'CC2306-DIEN'],
    8 => ['CC-2306', 'nuoc', 'sinh_hoat', '0.00', 'CC2306-NUOC'],
];
const E2E_SEED_VEHICLES = [['BT1', 'o_to', 1], ['S01', 'xe_may', 2]];

/**
 * @return array{tag:string, building:int, building_code:string, zones:array<string,int>, apt:array<string,int>, apt_code:array<string,string>,
 *   meter:array<int,int>, meter_code:array<int,string>, periods:int[]}
 */
function e2e_fixture_create(PDO $pdo, string $runId): array
{
    $tag = 'E' . substr(preg_replace('/\D/', '', $runId), -7);
    $fx = ['tag' => $tag, 'building' => 0, 'building_code' => 'E2E-' . $tag, 'zones' => [], 'apt' => [], 'apt_code' => [], 'meter' => [], 'meter_code' => [], 'periods' => []];
    $pdo->prepare("INSERT INTO buildings (code, name, status, created_by) VALUES (?, ?, 'hoat_dong', 1)")->execute([$fx['building_code'], 'Tòa E2E ' . $tag]);
    $fx['building'] = (int) $pdo->lastInsertId();
    foreach (E2E_SEED_ZONES as $key => [$code, $name, $type]) {
        $pdo->prepare("INSERT INTO zones (building_id, code, name, zone_type, status) VALUES (?, ?, ?, ?, 'hoat_dong')")->execute([$fx['building'], $code, $name . ' ' . $tag, $type]);
        $fx['zones'][$key] = (int) $pdo->lastInsertId();
    }
    foreach (E2E_SEED_APARTMENTS as $key => [$zone, $code, $area, $occupancy]) {
        $fx['apt_code'][$key] = $tag . '-' . $code;
        $pdo->prepare("INSERT INTO apartments (building_id, zone_id, code, area, occupancy_status, status) VALUES (?, ?, ?, ?, ?, 'hoat_dong')")
            ->execute([$fx['building'], $fx['zones'][$zone], $fx['apt_code'][$key], $area, $occupancy]);
        $fx['apt'][$key] = (int) $pdo->lastInsertId();
    }
    foreach (E2E_SEED_METERS as $seedId => [$apt, $utility, $usage, $initial, $code]) {
        $fx['meter_code'][$seedId] = $tag . '-' . $code;
        $pdo->prepare("INSERT INTO meters (apartment_id, meter_code, utility_type, usage_type, initial_reading, status) VALUES (?, ?, ?, ?, ?, 'hoat_dong')")
            ->execute([$fx['apt'][$apt], $fx['meter_code'][$seedId], $utility, $usage, $initial]);
        $fx['meter'][$seedId] = (int) $pdo->lastInsertId();
    }
    foreach (E2E_SEED_VEHICLES as [$apt, $type, $qty]) {
        $pdo->prepare('INSERT INTO parking_vehicles (apartment_id, vehicle_type, quantity) VALUES (?, ?, ?)')->execute([$fx['apt'][$apt], $type, $qty]);
    }

    return $fx;
}

/**
 * Kỳ test cần khoá: nhập chỉ số CL = 0 (CSC = CSĐ) cho công tơ đang hoạt động KHÔNG thuộc fixture, chỉ trong kỳ $periodId (Kỹ thuật).
 * @return array{created:int, blocked:array<string,string>} blocked = công tơ thật không nhập được (vd B7) - phụ thuộc dữ liệu thật
 */
function e2e_fill_other_meters(PDO $pdo, array $fx, int $periodId, int $technicianId = 2): array
{
    $saved = $_SESSION ?? [];
    $_SESSION = ['user_id' => $technicianId, 'user_role' => 'ky_thuat', 'username' => 'e2e-fixture', 'full_name' => 'E2E fixture'];
    $service = new MeterReadingService();
    $period = $pdo->query("SELECT * FROM billing_periods WHERE id = {$periodId}")->fetch();
    $ids = array_map('intval', $pdo->query("SELECT m.id FROM meters m JOIN apartments a ON a.id = m.apartment_id
        WHERE m.status = 'hoat_dong' AND a.status = 'hoat_dong'
          AND NOT EXISTS (SELECT 1 FROM meter_readings r WHERE r.meter_id = m.id AND r.billing_period_id = {$periodId})")->fetchAll(PDO::FETCH_COLUMN));
    $out = ['created' => 0, 'blocked' => []];
    foreach (array_diff($ids, array_values($fx['meter'])) as $meterId) {
        $meter = $service->meterWithApartment($meterId);
        $prev = $service->previousReading($meter, $period);
        if ($prev['blocked'] !== null) {
            $out['blocked'][$meter['meter_code']] = 'B7 - chỉ số kỳ ' . $prev['from_period'] . ' còn Nháp';
            continue;
        }
        try {
            $service->create($periodId, $meterId, ['current_reading' => (string) $prev['value'], 'note' => 'E2E fixture ' . $fx['tag'] . ' (CL = 0)']);
            $out['created']++;
        } catch (\App\Core\ValidationException $e) {
            $out['blocked'][$meter['meter_code']] = $e->getMessage();
        }
    }
    $_SESSION = $saved;

    return $out;
}

/** Xoá đúng dữ liệu test: kỳ test ($fx['periods']) + mọi bản ghi gắn với id fixture. Không đụng dữ liệu khác. */
function e2e_cleanup(PDO $pdo, array $fx): void
{
    $periods = array_values(array_filter(array_map('intval', $fx['periods'])));
    $apts = array_values($fx['apt']);
    $meters = array_values($fx['meter']);
    $zones = array_values($fx['zones']);
    $in = static fn (array $ids): string => $ids === [] ? '0' : implode(',', array_map('intval', $ids));
    $p = $in($periods);
    $a = $in($apts);
    $m = $in($meters);
    $z = $in($zones);
    $b = (int) $fx['building'];
    $alerts = "SELECT id FROM alerts WHERE billing_period_id IN ({$p}) OR apartment_id IN ({$a})";
    foreach ([
        "DELETE FROM alert_contact_logs WHERE alert_id IN ({$alerts})",
        "DELETE FROM email_notifications WHERE alert_id IN ({$alerts}) OR apartment_id IN ({$a}) OR billing_period_id IN ({$p})",
        "DELETE FROM alerts WHERE billing_period_id IN ({$p}) OR apartment_id IN ({$a})", // cascade lịch sử, case, khuyến nghị
        "DELETE FROM consumption_targets WHERE billing_period_id IN ({$p}) OR (scope_type = 'apartment' AND scope_id IN ({$a}))
            OR (scope_type = 'zone' AND scope_id IN ({$z})) OR (scope_type = 'building' AND scope_id = {$b})",
        "DELETE FROM baselines WHERE (scope_type = 'meter' AND scope_id IN ({$m})) OR (scope_type = 'apartment' AND scope_id IN ({$a}))
            OR (scope_type = 'zone' AND scope_id IN ({$z})) OR (scope_type = 'building' AND scope_id = {$b})",
        "DELETE d FROM charge_tier_details d JOIN apartment_charges c ON c.id = d.apartment_charge_id WHERE c.billing_period_id IN ({$p}) OR c.apartment_id IN ({$a})",
        "DELETE FROM apartment_charges WHERE billing_period_id IN ({$p}) OR apartment_id IN ({$a})",
        "DELETE FROM meter_readings WHERE billing_period_id IN ({$p}) OR meter_id IN ({$m})",
        "DELETE e FROM import_errors e JOIN import_batches ib ON ib.id = e.import_batch_id WHERE ib.billing_period_id IN ({$p})",
        "DELETE FROM import_batches WHERE billing_period_id IN ({$p})",
        "DELETE FROM billing_periods WHERE id IN ({$p})",
        "DELETE FROM parking_vehicles WHERE apartment_id IN ({$a})",
        "DELETE FROM residents WHERE apartment_id IN ({$a})",
        "DELETE FROM meters WHERE id IN ({$m})",
        "DELETE FROM apartments WHERE id IN ({$a})",
        "DELETE FROM zones WHERE id IN ({$z})",
        "DELETE FROM buildings WHERE id = {$b}",
    ] as $sql) {
        $pdo->exec($sql);
    }
}

/** Số bản ghi còn sót thuộc phạm vi test (0 = sạch). Chỉ đếm theo id fixture / kỳ test - không đếm dữ liệu người dùng. */
function e2e_leftovers(PDO $pdo, array $fx): int
{
    $in = static fn (array $ids): string => $ids === [] ? '0' : implode(',', array_map('intval', $ids));
    $p = $in(array_values(array_filter(array_map('intval', $fx['periods']))));
    $a = $in(array_values($fx['apt']));
    $m = $in(array_values($fx['meter']));

    return (int) $pdo->query("SELECT
        (SELECT COUNT(*) FROM billing_periods WHERE id IN ({$p})) + (SELECT COUNT(*) FROM meter_readings WHERE billing_period_id IN ({$p}) OR meter_id IN ({$m}))
        + (SELECT COUNT(*) FROM apartment_charges WHERE billing_period_id IN ({$p}) OR apartment_id IN ({$a}))
        + (SELECT COUNT(*) FROM alerts WHERE billing_period_id IN ({$p}) OR apartment_id IN ({$a}))
        + (SELECT COUNT(*) FROM meters WHERE id IN ({$m})) + (SELECT COUNT(*) FROM apartments WHERE id IN ({$a}))
        + (SELECT COUNT(*) FROM buildings WHERE id = " . (int) $fx['building'] . ')')->fetchColumn();
}

/**
 * Chọn tháng cho KỲ TEST cần KHOÁ trên DB thật (khoá kỳ cần MỌI công tơ đang hoạt động có chỉ số Đã xác nhận - quy tắc không đổi).
 * Công tơ thật nhập được CL = 0 trong kỳ test chỉ khi chỉ số GẦN NHẤT TRƯỚC kỳ (MeterReadingRepository::latestBefore) Đã xác nhận / chưa có (B7).
 * Ưu tiên năm xa $preferredYear (như trước); nếu người dùng đang có kỳ còn chỉ số Nháp (vd 07/2026) chặn năm đó thì dùng tháng TRỐNG
 * đầu tiên sau kỳ đã khoá muộn nhất mà không công tơ nào bị chặn (vd 08/2024..06/2026). Không đọc / sửa dữ liệu người dùng ngoài truy vấn chỉ đọc.
 * @return array{month:int, year:int, code:string}
 */
function e2e_pick_period(PDO $pdo, int $preferredYear): array
{
    $months = range(1, 12);
    shuffle($months);
    $candidates = array_map(static fn (int $m): array => [$m, $preferredYear], $months);
    $lastLocked = (string) ($pdo->query("SELECT MAX(end_date) FROM billing_periods WHERE status = 'da_khoa'")->fetchColumn() ?: '1999-12-31');
    $d = (new DateTimeImmutable($lastLocked))->modify('first day of next month');
    for (; (int) $d->format('Y') <= 2099; $d = $d->modify('+1 month')) {
        $candidates[] = [(int) $d->format('n'), (int) $d->format('Y')];
    }
    $overlap = $pdo->prepare('SELECT COUNT(*) FROM billing_periods WHERE period_code = :c OR (start_date <= :e AND end_date >= :s)');
    $blocked = $pdo->prepare("SELECT COUNT(*) FROM meters m JOIN apartments a ON a.id = m.apartment_id
        WHERE m.status = 'hoat_dong' AND a.status = 'hoat_dong'
          AND (SELECT r.status FROM meter_readings r JOIN billing_periods p ON p.id = r.billing_period_id
               WHERE r.meter_id = m.id AND p.end_date < :s ORDER BY p.end_date DESC LIMIT 1) <> 'da_xac_nhan'");
    foreach ($candidates as [$m, $y]) {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));
        $s = $first->format('Y-m-d');
        $e = $first->format('Y-m-t');
        if ($s <= $lastLocked) {
            continue;
        }
        $overlap->execute(['c' => $first->format('m/Y'), 's' => $s, 'e' => $e]);
        if ((int) $overlap->fetchColumn() > 0) {
            continue;
        }
        $blocked->execute(['s' => $s]);
        if ((int) $blocked->fetchColumn() === 0) {
            return ['month' => $m, 'year' => $y, 'code' => $first->format('m/Y')];
        }
    }
    throw new RuntimeException('Không tìm được tháng trống cho kỳ test (mọi tháng đều có công tơ bị chặn B7).');
}

/** Quy tắc cảnh báo / khuyến nghị do lượt chạy tạo: tên bắt đầu "E2E" và chứa mã lượt chạy. */
function e2e_rule_like(string $runId): string
{
    return 'E2E%' . $runId . '%';
}

/** Xoá quy tắc do lượt chạy tạo (+ cảnh báo sinh từ các quy tắc đó; lịch sử / case / khuyến nghị cascade). Gọi SAU e2e_cleanup. */
function e2e_rules_cleanup(PDO $pdo, string $runId): void
{
    $like = e2e_rule_like($runId);
    $pdo->prepare('DELETE FROM alerts WHERE alert_rule_id IN (SELECT id FROM alert_rules WHERE name LIKE ?)')->execute([$like]);
    $pdo->prepare('DELETE FROM recommendations WHERE recommendation_rule_id IN (SELECT id FROM recommendation_rules WHERE name LIKE ?)')->execute([$like]);
    $pdo->prepare('DELETE FROM recommendation_rules WHERE name LIKE ?')->execute([$like]);
    $pdo->prepare('DELETE FROM alert_rules WHERE name LIKE ?')->execute([$like]);
}

/** Số quy tắc / cảnh báo / khuyến nghị do lượt chạy tạo còn sót (0 = sạch). */
function e2e_rules_leftovers(PDO $pdo, string $runId): int
{
    $s = $pdo->prepare('SELECT (SELECT COUNT(*) FROM alert_rules WHERE name LIKE :a) + (SELECT COUNT(*) FROM recommendation_rules WHERE name LIKE :b)
        + (SELECT COUNT(*) FROM alerts WHERE alert_rule_id IN (SELECT id FROM alert_rules WHERE name LIKE :c))');
    $like = e2e_rule_like($runId);
    $s->execute(['a' => $like, 'b' => $like, 'c' => $like]);

    return (int) $s->fetchColumn();
}

/**
 * Kỳ test ĐÃ KHOÁ cho tòa fixture (dashboard / báo cáo / baseline cần kỳ đã khoá): tạo kỳ (e2e_pick_period) → 8 chỉ số fixture mô phỏng
 * CL 06/2024 của seed (điện 643 = 343 + 150 + 150 + 0, nước 58 = 16 + 30 + 12 + 0) → công tơ khác CL = 0 chỉ trong kỳ test → duyệt → tính phí
 * → xác nhận → khoá. Chạy bằng Service (vai trò đúng quyền); kỳ được thêm vào $fx['periods'] để e2e_cleanup xoá.
 * @return array{id:int, code:string, start:string, end:string, blocked:array<string,string>, others:int}
 */
function e2e_create_locked_period(PDO $pdo, array &$fx, int $preferredYear, string $runId): array
{
    $saved = $_SESSION ?? [];
    $as = static function (int $id, string $role): void {
        $_SESSION = ['user_id' => $id, 'user_role' => $role, 'username' => 'e2e-fixture', 'full_name' => 'E2E fixture'];
    };
    $tp = e2e_pick_period($pdo, $preferredYear);
    $as(1, 'bql');
    $periodId = (int) (new App\Services\BillingPeriodService())->create(['month' => (string) $tp['month'], 'year' => (string) $tp['year']])['id'];
    $fx['periods'][] = $periodId;
    $as(2, 'ky_thuat');
    foreach ([1 => '343', 2 => '16', 3 => '150', 4 => '30', 5 => '150', 6 => '12', 7 => '0', 8 => '0'] as $seedId => $delta) {
        (new MeterReadingService())->create($periodId, $fx['meter'][$seedId], ['current_reading' => bcadd(E2E_SEED_METERS[$seedId][3], $delta, 2), 'note' => "E2E {$runId}"]);
    }
    $others = e2e_fill_other_meters($pdo, $fx, $periodId);
    $as(1, 'bql');
    $items = [];
    foreach ($pdo->query("SELECT * FROM meter_readings WHERE billing_period_id = {$periodId}")->fetchAll() as $row) {
        $items[(int) $row['id']] = MeterReadingService::versionToken($row);
    }
    (new App\Services\ReadingApprovalService())->approve($periodId, $items);
    $as(3, 'ke_toan');
    (new App\Services\BillingCalculationService())->calculatePeriod($periodId);
    $tokens = [];
    foreach ($pdo->query("SELECT * FROM apartment_charges WHERE billing_period_id = {$periodId}")->fetchAll() as $c) {
        $tokens[(int) $c['id']] = App\Services\BillingCalculationService::versionToken($c);
    }
    (new App\Services\BillingConfirmationService())->confirm($periodId, $tokens);
    if ($others['blocked'] === []) {
        (new App\Services\PeriodClosingService())->lock($periodId);
    }
    $_SESSION = $saved;
    $row = $pdo->query("SELECT period_code, start_date, end_date FROM billing_periods WHERE id = {$periodId}")->fetch();

    return ['id' => $periodId, 'code' => (string) $row['period_code'], 'start' => (string) $row['start_date'], 'end' => (string) $row['end_date'],
        'blocked' => $others['blocked'], 'others' => $others['created']];
}
