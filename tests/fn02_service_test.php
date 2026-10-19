<?php

declare(strict_types=1);

/**
 * STEP 6 - Test cấp Service cho FN-02 (validation, business rule, audit).
 *
 * Chạy trên DATABASE TẠM (không phải energy_dss thật) vì test tạo/sửa/ngừng dữ liệu:
 *   php tests/fn02_service_test.php energy_dss_fn02_test
 * (tests/run_fn02_tests.sh tự tạo DB tạm từ database/energy_dss_v2.sql rồi xoá sau khi chạy.)
 * Thoát mã 0 nếu mọi test PASS.
 */

$testDb = $argv[1] ?? '';
if ($testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Cần tên database TẠM (khác energy_dss), vd: php tests/fn02_service_test.php energy_dss_fn02_test\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';

// Trỏ app sang DB tạm TRƯỚC khi Config/Database được nạp lần đầu.
$_ENV['DB_DATABASE'] = $testDb;
restore_error_handler();
restore_exception_handler();

use App\Core\Database;
use App\Core\ValidationException;
use App\Services\ApartmentService;
use App\Services\BuildingService;
use App\Services\MeterService;
use App\Services\ParkingVehicleService;
use App\Services\ResidentService;
use App\Services\ZoneService;

// Actor của audit/created_by = BQL seed (id 1). RBAC kiểm ở route - xem fn02_http_test.php.
$_SESSION = ['user_id' => 1, 'user_role' => 'bql', 'username' => 'bql01', 'full_name' => 'Test'];
$_SERVER['REMOTE_ADDR'] = 'fn02-service-test';

$pdo = Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng để bảo vệ DB thật.\n");
    exit(2);
}

$results = [];
function check(string $group, string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$group, $name, $ok, $detail];
}

/** Chạy $fn, mong đợi ValidationException có lỗi ở $field. */
function expectError(string $group, string $name, string $field, callable $fn): void
{
    try {
        $fn();
        check($group, $name, false, 'không có lỗi');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        check($group, $name, isset($errors[$field]), isset($errors[$field]) ? '' : 'lỗi ở trường khác: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
    }
}

function expectOk(string $group, string $name, callable $fn)
{
    try {
        $result = $fn();
        check($group, $name, true);

        return $result;
    } catch (ValidationException $e) {
        check($group, $name, false, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

        return null;
    }
}

function auditCount(PDO $pdo, string $action, string $entity, int $id): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_type = ? AND entity_id = ? AND user_id = 1');
    $stmt->execute([$action, $entity, (string) $id]);

    return (int) $stmt->fetchColumn();
}

function row(PDO $pdo, string $table, int $id): array
{
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);

    return $stmt->fetch() ?: [];
}

$buildings = new BuildingService();
$zones = new ZoneService();
$apartments = new ApartmentService();
$residents = new ResidentService();
$meters = new MeterService();
$vehicles = new ParkingVehicleService();

// =========================================================== BUILDING (FN-02.01)
$G = 'BUILDING';
$b1 = expectOk($G, 'create hợp lệ', fn () => $buildings->create(['code' => 'T-B1', 'name' => 'Tòa thử 1']));
check($G, 'create: status=hoat_dong, created_by=actor, audit create', row($pdo, 'buildings', $b1)['status'] === 'hoat_dong'
    && (int) row($pdo, 'buildings', $b1)['created_by'] === 1 && auditCount($pdo, 'create', 'building', $b1) === 1);
expectError($G, 'duplicate code (T-B1)', 'code', fn () => $buildings->create(['code' => 'T-B1', 'name' => 'Trùng']));
expectError($G, 'duplicate code không phân biệt hoa thường (t-b1)', 'code', fn () => $buildings->create(['code' => 't-b1', 'name' => 'Trùng']));
expectError($G, 'thiếu mã', 'code', fn () => $buildings->create(['code' => '', 'name' => 'X']));
expectError($G, 'mã có khoảng trắng', 'code', fn () => $buildings->create(['code' => 'A B', 'name' => 'X']));
expectError($G, 'mã > 30 ký tự', 'code', fn () => $buildings->create(['code' => str_repeat('A', 31), 'name' => 'X']));
expectError($G, 'thiếu tên', 'name', fn () => $buildings->create(['code' => 'T-B9', 'name' => '']));
$stamp = row($pdo, 'buildings', $b1)['updated_at'];
check($G, 'edit hợp lệ -> true', expectOk($G, 'edit (đổi tên)', fn () => $buildings->update($b1, ['code' => 'T-B1', 'name' => 'Tòa thử 1 (sửa)', 'updated_at' => $stamp])) === true);
check($G, 'edit: audit update lưu old/new', (function () use ($pdo, $b1): bool {
    $stmt = $pdo->prepare("SELECT old_value, new_value FROM audit_logs WHERE action='update' AND entity_type='building' AND entity_id=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([(string) $b1]);
    $r = $stmt->fetch();

    return $r && json_decode($r['old_value'], true)['name'] === 'Tòa thử 1' && json_decode($r['new_value'], true)['name'] === 'Tòa thử 1 (sửa)';
})());
$stamp = row($pdo, 'buildings', $b1)['updated_at'];
check($G, 'edit không đổi gì -> false, không ghi audit', $buildings->update($b1, ['code' => 'T-B1', 'name' => 'Tòa thử 1 (sửa)', 'updated_at' => $stamp]) === false
    && auditCount($pdo, 'update', 'building', $b1) === 1);
expectError($G, 'edit với updated_at cũ (người khác đã sửa) -> từ chối', '_', fn () => $buildings->update($b1, ['code' => 'T-B1', 'name' => 'Ghi đè', 'updated_at' => '2000-01-01 00:00:00']));
expectError($G, 'edit đổi mã trùng tòa khác (CHUNGCU-A)', 'code', fn () => $buildings->update($b1, ['code' => 'CHUNGCU-A', 'name' => 'X', 'updated_at' => row($pdo, 'buildings', $b1)['updated_at']]));
expectError($G, 'deactivate tòa còn khu/căn hộ hoạt động (seed id 1) -> chặn', '_', fn () => $buildings->deactivate(1));

// =========================================================== ZONE (FN-02.02)
$G = 'ZONE';
$z1 = expectOk($G, 'create hợp lệ trong tòa T-B1', fn () => $zones->create(['building_id' => (string) $b1, 'code' => 'K1', 'name' => 'Khu 1', 'zone_type' => 'lien_ke']));
check($G, 'create: audit create', auditCount($pdo, 'create', 'zone', (int) $z1) === 1);
expectError($G, 'wrong building (id không tồn tại)', 'building_id', fn () => $zones->create(['building_id' => '999999', 'code' => 'K9', 'name' => 'X', 'zone_type' => '']));
expectError($G, 'building_id không phải số', 'building_id', fn () => $zones->create(['building_id' => 'abc', 'code' => 'K9', 'name' => 'X', 'zone_type' => '']));
expectError($G, 'duplicate code trong cùng tòa', 'code', fn () => $zones->create(['building_id' => (string) $b1, 'code' => 'K1', 'name' => 'X', 'zone_type' => '']));
expectOk($G, 'cùng mã K1 nhưng khác tòa (seed tòa 1) -> cho phép', fn () => $zones->create(['building_id' => '1', 'code' => 'K1', 'name' => 'Khu K1 tòa A', 'zone_type' => '']));
expectError($G, 'loại khu không hợp lệ', 'zone_type', fn () => $zones->create(['building_id' => (string) $b1, 'code' => 'K8', 'name' => 'X', 'zone_type' => 'nha_xuong']));
$bInactive = $buildings->create(['code' => 'T-BNGUNG', 'name' => 'Tòa sẽ ngừng']);
$buildings->deactivate($bInactive);
check($G, 'deactivate tòa trống -> OK + audit deactivate', row($pdo, 'buildings', $bInactive)['status'] === 'ngung' && auditCount($pdo, 'deactivate', 'building', $bInactive) === 1);
expectError($G, 'deactivate lại tòa đã ngừng', '_', fn () => $buildings->deactivate($bInactive));
expectError($G, 'inactive building -> không tạo khu', 'building_id', fn () => $zones->create(['building_id' => (string) $bInactive, 'code' => 'K1', 'name' => 'X', 'zone_type' => '']));

// =========================================================== APARTMENT (FN-02.03)
$G = 'APARTMENT';
$a1 = expectOk($G, 'create hợp lệ (tòa T-B1, khu K1)', fn () => $apartments->create(['building_id' => (string) $b1, 'zone_id' => (string) $z1, 'code' => 'A101', 'area' => '75.5', 'occupancy_status' => 'co_o']));
check($G, 'create: area lưu 75.50, audit create', row($pdo, 'apartments', $a1)['area'] === '75.50' && auditCount($pdo, 'create', 'apartment', (int) $a1) === 1);
$aNoZone = expectOk($G, 'create không chọn khu (zone_id NULL - D6)', fn () => $apartments->create(['building_id' => (string) $b1, 'zone_id' => '', 'code' => 'A102', 'area' => '80,25', 'occupancy_status' => 'khong_phat_sinh']));
check($G, 'diện tích dấu phẩy "80,25" -> 80.25', row($pdo, 'apartments', (int) $aNoZone)['area'] === '80.25');
foreach (['0' => 'area = 0', '-5' => 'area âm', 'abc' => 'area không phải số', '1.234' => 'area 3 chữ số thập phân', '' => 'area trống'] as $area => $label) {
    expectError($G, $label, 'area', fn () => $apartments->create(['building_id' => (string) $b1, 'zone_id' => '', 'code' => 'A199', 'area' => (string) $area, 'occupancy_status' => 'co_o']));
}
expectError($G, 'duplicate code trong cùng tòa', 'code', fn () => $apartments->create(['building_id' => (string) $b1, 'zone_id' => '', 'code' => 'A101', 'area' => '50', 'occupancy_status' => 'co_o']));
expectOk($G, 'cùng mã A101 ở tòa khác -> cho phép (UNIQUE building_id+code)', fn () => $apartments->create(['building_id' => '1', 'zone_id' => '', 'code' => 'A101', 'area' => '50', 'occupancy_status' => 'co_o']));
expectError($G, 'zone/building mismatch (khu KHU-BT của tòa 1, tòa T-B1)', 'zone_id', fn () => $apartments->create(['building_id' => (string) $b1, 'zone_id' => '1', 'code' => 'A103', 'area' => '50', 'occupancy_status' => 'co_o']));
expectError($G, 'zone không tồn tại', 'zone_id', fn () => $apartments->create(['building_id' => (string) $b1, 'zone_id' => '999999', 'code' => 'A103', 'area' => '50', 'occupancy_status' => 'co_o']));
expectError($G, 'inactive parent: tòa đã ngừng', 'building_id', fn () => $apartments->create(['building_id' => (string) $bInactive, 'zone_id' => '', 'code' => 'A103', 'area' => '50', 'occupancy_status' => 'co_o']));
$zInactive = $zones->create(['building_id' => (string) $b1, 'code' => 'K-NGUNG', 'name' => 'Khu sẽ ngừng', 'zone_type' => '']);
$zones->deactivate($zInactive);
expectError($G, 'inactive parent: khu đã ngừng', 'zone_id', fn () => $apartments->create(['building_id' => (string) $b1, 'zone_id' => (string) $zInactive, 'code' => 'A103', 'area' => '50', 'occupancy_status' => 'co_o']));
expectError($G, 'trạng thái sử dụng không hợp lệ', 'occupancy_status', fn () => $apartments->create(['building_id' => (string) $b1, 'zone_id' => '', 'code' => 'A104', 'area' => '50', 'occupancy_status' => 'cho_thue']));

// ZONE dependency (cần căn hộ A101 trong K1)
$G = 'ZONE';
expectError($G, 'dependency: ngừng khu còn căn hộ hoạt động', '_', fn () => $zones->deactivate((int) $z1));
expectError($G, 'dependency: đổi tòa của khu đã có căn hộ', 'building_id', fn () => $zones->update((int) $z1, ['building_id' => '1', 'code' => 'K1X', 'name' => 'Khu 1', 'zone_type' => 'lien_ke', 'updated_at' => row($pdo, 'zones', (int) $z1)['updated_at']]));

// =========================================================== METER (FN-02.05)
$G = 'METER';
$m1 = expectOk($G, 'create hợp lệ (A101, điện, sinh hoạt, CSĐ 0)', fn () => $meters->create(['apartment_id' => (string) $a1, 'meter_code' => 'A101-DIEN', 'utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'display_label' => 'ở', 'initial_reading' => '0']));
check($G, 'create: audit create', auditCount($pdo, 'create', 'meter', (int) $m1) === 1);
expectError($G, 'duplicate meter code (seed BT1-DIEN)', 'meter_code', fn () => $meters->create(['apartment_id' => (string) $a1, 'meter_code' => 'BT1-DIEN', 'utility_type' => 'nuoc', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '0']));
expectError($G, 'negative initial reading', 'initial_reading', fn () => $meters->create(['apartment_id' => (string) $a1, 'meter_code' => 'A101-N1', 'utility_type' => 'nuoc', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '-1']));
expectError($G, 'thiếu initial reading', 'initial_reading', fn () => $meters->create(['apartment_id' => (string) $a1, 'meter_code' => 'A101-N1', 'utility_type' => 'nuoc', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '']));
expectError($G, 'invalid utility (gas)', 'utility_type', fn () => $meters->create(['apartment_id' => (string) $a1, 'meter_code' => 'A101-N1', 'utility_type' => 'gas', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '0']));
expectError($G, 'thiếu utility (VAL-02.06)', 'utility_type', fn () => $meters->create(['apartment_id' => (string) $a1, 'meter_code' => 'A101-N1', 'utility_type' => '', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '0']));
expectError($G, 'invalid usage (cong_nghiep)', 'usage_type', fn () => $meters->create(['apartment_id' => (string) $a1, 'meter_code' => 'A101-N1', 'utility_type' => 'nuoc', 'usage_type' => 'cong_nghiep', 'display_label' => '', 'initial_reading' => '0']));
expectError($G, 'apartment không tồn tại', 'apartment_id', fn () => $meters->create(['apartment_id' => '999999', 'meter_code' => 'A101-N1', 'utility_type' => 'nuoc', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '0']));
$aInactive = $apartments->create(['building_id' => (string) $b1, 'zone_id' => '', 'code' => 'A-NGUNG', 'area' => '40', 'occupancy_status' => 'co_o']);
$apartments->deactivate($aInactive);
check($G, 'ngừng căn hộ không có công tơ -> OK + audit', row($pdo, 'apartments', $aInactive)['status'] === 'ngung' && auditCount($pdo, 'deactivate', 'apartment', $aInactive) === 1);
expectError($G, 'inactive apartment (VAL-02.07)', 'apartment_id', fn () => $meters->create(['apartment_id' => (string) $aInactive, 'meter_code' => 'A-NGUNG-D', 'utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '0']));

$readingsBefore = (int) $pdo->query('SELECT COUNT(*) FROM meter_readings')->fetchColumn();
$seedMeter = row($pdo, 'meters', 1); // BT1-DIEN, đã có chỉ số kỳ 06/2024
expectError($G, 'D8: đổi loại sử dụng công tơ đã có chỉ số -> chặn', 'usage_type', fn () => $meters->update(1, [
    'apartment_id' => '1', 'meter_code' => 'BT1-DIEN', 'utility_type' => 'dien', 'usage_type' => 'kinh_doanh',
    'display_label' => '', 'initial_reading' => '1000', 'updated_at' => $seedMeter['updated_at'],
]));
expectError($G, 'D8: đổi chỉ số khởi tạo công tơ đã có chỉ số -> chặn', 'initial_reading', fn () => $meters->update(1, [
    'apartment_id' => '1', 'meter_code' => 'BT1-DIEN', 'utility_type' => 'dien', 'usage_type' => 'sinh_hoat',
    'display_label' => '', 'initial_reading' => '900', 'updated_at' => $seedMeter['updated_at'],
]));
check($G, 'D8: đổi nhãn công tơ đã có chỉ số -> cho phép', expectOk($G, 'update nhãn', fn () => $meters->update(1, [
    'apartment_id' => '1', 'meter_code' => 'BT1-DIEN', 'utility_type' => 'dien', 'usage_type' => 'sinh_hoat',
    'display_label' => 'ở', 'initial_reading' => '1000', 'updated_at' => $seedMeter['updated_at'],
])) === true);
$kept = expectOk($G, 'deactivate meter có chỉ số -> chỉ ngừng', fn () => $meters->deactivate(1));
check($G, 'deactivate meter có chỉ số: status=ngung, chỉ số giữ nguyên, audit readings_kept', row($pdo, 'meters', 1)['status'] === 'ngung'
    && (int) $pdo->query('SELECT COUNT(*) FROM meter_readings')->fetchColumn() === $readingsBefore && $kept === 1
    && auditCount($pdo, 'deactivate', 'meter', 1) === 1);
expectError($G, 'deactivate lại công tơ đã ngừng', '_', fn () => $meters->deactivate(1));

// APARTMENT dependency (cần công tơ m1 trong A101)
$G = 'APARTMENT';
expectError($G, 'deactivate căn còn công tơ hoạt động -> chặn', '_', fn () => $apartments->deactivate((int) $a1));
expectError($G, 'D7: đổi tòa của căn đã có công tơ -> chặn', 'building_id', fn () => $apartments->update((int) $a1, [
    'building_id' => '1', 'zone_id' => '', 'code' => 'A101X', 'area' => '75.5', 'occupancy_status' => 'co_o', 'updated_at' => row($pdo, 'apartments', (int) $a1)['updated_at'],
]));
check($G, 'edit diện tích -> OK', expectOk($G, 'update area', fn () => $apartments->update((int) $a1, [
    'building_id' => (string) $b1, 'zone_id' => (string) $z1, 'code' => 'A101', 'area' => '76', 'occupancy_status' => 'co_o', 'updated_at' => row($pdo, 'apartments', (int) $a1)['updated_at'],
])) === true && row($pdo, 'apartments', (int) $a1)['area'] === '76.00');

// =========================================================== OWNER/TENANT (FN-02.04)
$G = 'OWNER/TENANT';
$r1 = expectOk($G, 'create hợp lệ', fn () => $residents->create((int) $a1, ['full_name' => 'Nguyễn Văn Thử', 'resident_role' => 'chu_ho', 'phone' => '0901 234 567', 'effective_from' => '2024-01-01', 'effective_to' => '']));
check($G, 'create: is_active=1, apartment_id đúng, audit create', (int) row($pdo, 'residents', (int) $r1)['is_active'] === 1
    && (int) row($pdo, 'residents', (int) $r1)['apartment_id'] === (int) $a1 && auditCount($pdo, 'create', 'resident', (int) $r1) === 1);
expectError($G, 'required: thiếu họ tên', 'full_name', fn () => $residents->create((int) $a1, ['full_name' => '', 'resident_role' => 'chu_ho', 'phone' => '', 'effective_from' => '', 'effective_to' => '']));
expectError($G, 'required: thiếu vai trò', 'resident_role', fn () => $residents->create((int) $a1, ['full_name' => 'X', 'resident_role' => '', 'phone' => '', 'effective_from' => '', 'effective_to' => '']));
expectError($G, 'vai trò không hợp lệ', 'resident_role', fn () => $residents->create((int) $a1, ['full_name' => 'X', 'resident_role' => 'nguoi_than', 'phone' => '', 'effective_from' => '', 'effective_to' => '']));
expectError($G, 'apartment invalid (không tồn tại)', '_', fn () => $residents->create(999999, ['full_name' => 'X', 'resident_role' => 'chu_ho', 'phone' => '', 'effective_from' => '', 'effective_to' => '']));
expectError($G, 'apartment đã ngừng', '_', fn () => $residents->create($aInactive, ['full_name' => 'X', 'resident_role' => 'chu_ho', 'phone' => '', 'effective_from' => '', 'effective_to' => '']));
expectError($G, 'SĐT sai định dạng', 'phone', fn () => $residents->create((int) $a1, ['full_name' => 'X', 'resident_role' => 'chu_ho', 'phone' => 'abc-xyz', 'effective_from' => '', 'effective_to' => '']));
expectError($G, 'ngày kết thúc trước ngày bắt đầu', 'effective_to', fn () => $residents->create((int) $a1, ['full_name' => 'X', 'resident_role' => 'chu_ho', 'phone' => '', 'effective_from' => '2024-05-01', 'effective_to' => '2024-01-01']));
expectError($G, 'ngày không hợp lệ (2024-02-30)', 'effective_from', fn () => $residents->create((int) $a1, ['full_name' => 'X', 'resident_role' => 'chu_ho', 'phone' => '', 'effective_from' => '2024-02-30', 'effective_to' => '']));
expectOk($G, 'deactivate (kết thúc hiệu lực)', fn () => $residents->deactivate((int) $r1));
check($G, 'deactivate: is_active=0, effective_to=hôm nay, audit', (int) row($pdo, 'residents', (int) $r1)['is_active'] === 0
    && row($pdo, 'residents', (int) $r1)['effective_to'] === date('Y-m-d') && auditCount($pdo, 'deactivate', 'resident', (int) $r1) === 1);

// =========================================================== PARKING (FN-02.06)
$G = 'PARKING';
$valid = ['quantity_xe_dap_dien' => '0', 'quantity_xe_may' => '2', 'quantity_o_to' => '1'];
expectError($G, 'negative quantity', 'quantity_xe_may', fn () => $vehicles->update((int) $a1, ['quantity_xe_may' => '-1'] + $valid));
expectError($G, 'non-integer quantity (1.5)', 'quantity_o_to', fn () => $vehicles->update((int) $a1, ['quantity_o_to' => '1.5'] + $valid));
expectError($G, 'non-integer quantity (abc)', 'quantity_xe_dap_dien', fn () => $vehicles->update((int) $a1, ['quantity_xe_dap_dien' => 'abc'] + $valid));
expectError($G, 'thiếu số lượng', 'quantity_xe_may', fn () => $vehicles->update((int) $a1, ['quantity_xe_may' => ''] + $valid));
expectError($G, 'invalid apartment (không tồn tại)', '_', fn () => $vehicles->update(999999, $valid));
expectError($G, 'apartment đã ngừng', '_', fn () => $vehicles->update($aInactive, $valid));
check($G, 'update hợp lệ -> true', expectOk($G, 'update', fn () => $vehicles->update((int) $a1, $valid)) === true);
check($G, 'lưu đúng số lượng, không tạo dòng 0 thừa', $vehicles->quantities((int) $a1) === ['xe_dap_dien' => 0, 'xe_may' => 2, 'o_to' => 1]
    && (int) $pdo->query("SELECT COUNT(*) FROM parking_vehicles WHERE apartment_id = {$a1}")->fetchColumn() === 2);
check($G, 'update không đổi -> false', $vehicles->update((int) $a1, $valid) === false);
check($G, 'audit update (entity parking_vehicle, id = căn hộ)', auditCount($pdo, 'update', 'parking_vehicle', (int) $a1) === 1);

// =========================================================== XÂU CHUỖI NGỪNG (dependency đúng thứ tự)
$G = 'DEPENDENCY';
expectOk($G, 'ngừng công tơ A101-DIEN', fn () => $meters->deactivate((int) $m1));
expectOk($G, 'rồi ngừng căn A101 (hết công tơ HĐ)', fn () => $apartments->deactivate((int) $a1));
expectOk($G, 'ngừng khu K1 (không còn căn HĐ trong khu)', fn () => $zones->deactivate((int) $z1));
expectError($G, 'ngừng tòa T-B1 còn căn A102 hoạt động -> chặn', '_', fn () => $buildings->deactivate((int) $b1));

// ------------------------------------------------------------------ in kết quả
$failed = 0;
foreach ($results as [$group, $name, $ok, $detail]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-12s %s%s\n", $ok ? 'PASS' : 'FAIL', $group, $name, $ok || $detail === '' ? '' : "  -> {$detail}");
}
printf("\n%d/%d PASS (service test, db=%s)\n", count($results) - $failed, count($results), $testDb);
exit($failed === 0 ? 0 : 1);
