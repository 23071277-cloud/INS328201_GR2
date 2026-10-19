<?php

declare(strict_types=1);

/**
 * STEP 8 - Test cấp Service FN-04 (kỳ, nhập tay, validator, sửa, duyệt, import + control total, đồng thời).
 * Chạy trên DATABASE TẠM:  php tests/fn04_service_test.php energy_dss_fn04_test
 * Seed: kỳ 1 = 06/2024 (đã khoá, 8 chỉ số đã xác nhận), kỳ 2 = 07/2024 (mở).
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

use App\Core\Database;
use App\Core\ValidationException;
use App\Services\ApartmentService;
use App\Services\BillingPeriodService;
use App\Services\MeterReadingService;
use App\Services\MeterService;
use App\Services\ReadingApprovalService;
use App\Services\ReadingImportService;
use App\Support\XlsxWriter;

$pdo = Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SERVER['REMOTE_ADDR'] = 'fn04-service-test';
const BQL = 1;
const KT = 2;

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

function readingOf(PDO $pdo, int $meterId, int $periodId): array
{
    $s = $pdo->prepare('SELECT * FROM meter_readings WHERE meter_id = ? AND billing_period_id = ?');
    $s->execute([$meterId, $periodId]);

    return $s->fetch() ?: [];
}

function auditCount(PDO $pdo, string $action, string $entity, int $id): int
{
    return (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_type = ? AND entity_id = ?', [$action, $entity, (string) $id]);
}

/** Chạy song song các worker (tests/support/fn04_worker.php), cùng xuất phát. */
function race(string $db, array $jobs): array
{
    $start = microtime(true) + 1.5;
    $procs = [];
    $pipes = [];
    foreach ($jobs as $i => $job) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/fn04_worker.php') . ' ' . escapeshellarg($db) . ' ' . sprintf('%F', $start)
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

/** File xlsx thử nghiệm theo mẫu cột hệ thống (J-12). $rows: [STT, CH, ĐiệnCSĐ, ĐiệnCSC, ĐiệnCL, NướcCSĐ, NướcCSC, NướcCL] */
function xlsx(array $rows, ?array $total = null, ?array $headers = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'fn04') . '.xlsx';
    $data = [$headers ?? config('readings.import.headers')];
    foreach ($rows as [$stt, $ch, $dCsd, $dCsc, $dCl, $nCsd, $nCsc, $nCl]) {
        $data[] = [$stt, $ch, '', $dCsd, $dCsc, $dCl, $nCsd, $nCsc, $nCl];
    }
    if ($total !== null) {
        $data[] = ['Tổng', '', '', null, null, $total[0], null, null, $total[1]];
    }
    XlsxWriter::write($path, $data);

    return $path;
}

function upload(int $periodId, string $path, string $name = 'chi_so.xlsx'): int
{
    return (new ReadingImportService())->upload($periodId, ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)]);
}

$periods = new BillingPeriodService();
$readings = new MeterReadingService();
$approvals = new ReadingApprovalService();
$imports = new ReadingImportService();
$meters = new MeterService();
$apartments = new ApartmentService();
$P1 = 1;
$P2 = 2;

// ================================================================== PERIOD (FN-04.01)
$G = 'PERIOD';
actAs(BQL, 'bql');
$res = expectOk($G, 'create valid period 08/2024 (BQL - ASSUMPTION J-10)', fn () => $periods->create(['month' => '8', 'year' => '2024', 'start_date' => '', 'end_date' => '', 'reading_due_date' => '2024-08-05']));
$P3 = (int) ($res['id'] ?? 0);
check($G, 'create: mã 08/2024, 01/08–31/08, trạng thái Mở, created_by BQL, audit CREATE_BILLING_PERIOD',
    q($pdo, "SELECT CONCAT(period_code,'|',start_date,'|',end_date,'|',status,'|',created_by) FROM billing_periods WHERE id = ?", [$P3]) === '08/2024|2024-08-01|2024-08-31|mo|1'
    && auditCount($pdo, 'CREATE_BILLING_PERIOD', 'billing_period', $P3) === 1);
check($G, 'create: cảnh báo kỳ trước (07/2024) chưa khóa [ĐX]', str_contains((string) ($res['warning'] ?? ''), '07/2024'));
expectError($G, 'duplicate period 08/2024 (VAL-04.01)', 'month', fn () => $periods->create(['month' => '8', 'year' => '2024']), 'Kỳ này đã được tạo');
expectError($G, 'khoảng ngày chồng kỳ khác', 'start_date', fn () => $periods->create(['month' => '9', 'year' => '2024', 'start_date' => '2024-08-20', 'end_date' => '2024-09-30']), '08/2024');
expectError($G, 'tháng 13', 'month', fn () => $periods->create(['month' => '13', 'year' => '2024']));
expectError($G, 'năm 1999', 'year', fn () => $periods->create(['month' => '1', 'year' => '1999']));
expectError($G, 'ngày kết thúc trước ngày bắt đầu', 'end_date', fn () => $periods->create(['month' => '10', 'year' => '2024', 'start_date' => '2024-10-20', 'end_date' => '2024-10-05']));
check($G, 'kỳ 07/2024: 8 công tơ đang hoạt động chờ ghi số', count($readings->pendingMeters($P2)) === 8);
$prev = $readings->previousReading($meters->find(1), $periods->find($P2));
check($G, 'previous period reading exists: CSĐ công tơ BT1-DIEN = CSC đã xác nhận 06/2024 (1343)', $prev['value'] === '1343.00' && $prev['source'] === 'reading' && $prev['from_period'] === '06/2024');
$kd2 = $meters->create(['apartment_id' => '2', 'meter_code' => 'S01-DIEN-KD2', 'utility_type' => 'dien', 'usage_type' => 'kinh_doanh', 'display_label' => 'KD2', 'initial_reading' => '77.5']);
$prev = $readings->previousReading($meters->find($kd2), $periods->find($P2));
check($G, 'initial reading fallback: công tơ mới chưa có chỉ số -> CSĐ = chỉ số khởi tạo 77.5', $prev['value'] === '77.50' && $prev['source'] === 'initial');
$meters->deactivate(8);
check($G, 'inactive meter excluded: CC2306-NUOC (đã ngừng) không nằm trong danh sách chờ', !in_array(8, array_map('intval', array_column($readings->pendingMeters($P2), 'id')), true)
    && count($readings->pendingMeters($P2)) === 8);

// ================================================================== READING (FN-04.02 / 04.04)
$G = 'READING';
actAs(KT, 'ky_thuat');
$r1 = expectOk($G, 'current = previous (BT1-DIEN 1343) -> CL 0', fn () => $readings->create($P2, 1, ['current_reading' => '1343', 'note' => '']));
$row1 = readingOf($pdo, 1, $P2);
check($G, 'lưu: Nháp, nguồn thủ công, import_batch_id NULL, CSĐ do hệ thống, CL 0.00, audit CREATE_METER_READING',
    $row1['status'] === 'nhap' && $row1['source'] === 'thu_cong' && $row1['import_batch_id'] === null && $row1['previous_reading'] === '1343.00'
    && $row1['consumption'] === '0.00' && (int) $row1['entered_by'] === KT && auditCount($pdo, 'CREATE_METER_READING', 'meter_reading', (int) $row1['id']) === 1);
expectOk($G, 'current > previous (BT1-NUOC 216 -> 230)', fn () => $readings->create($P2, 2, ['current_reading' => '230', 'note' => 'ok']));
check($G, 'CL = CSC − CSĐ do server tính = 14.00', readingOf($pdo, 2, $P2)['consumption'] === '14.00');
$err = expectError($G, 'current < previous (S01-DIEN 650 -> 600) -> VAL-04.02', 'current_reading', fn () => $readings->create($P2, 3, ['current_reading' => '600']), 'xác nhận thay công tơ');
check($G, 'current < previous: trả cờ meter_reset để UI chỉ ra luồng thay/reset (FN-04.06), KHÔNG lưu', isset($err['meter_reset']) && readingOf($pdo, 3, $P2) === []);
expectError($G, 'negative (-5) -> VAL-04.06', 'current_reading', fn () => $readings->create($P2, 3, ['current_reading' => '-5']), 'không được âm');
expectError($G, 'non numeric (abc) -> VAL-04.01', 'current_reading', fn () => $readings->create($P2, 3, ['current_reading' => 'abc']), 'phải là số');
expectError($G, 'trống -> VAL-04.01', 'current_reading', fn () => $readings->create($P2, 3, ['current_reading' => '']));
expectError($G, '3 chữ số thập phân -> VAL-04.01', 'current_reading', fn () => $readings->create($P2, 3, ['current_reading' => '700.123']));
expectError($G, 'malformed array input (Request trả null) -> VAL-04.01', 'current_reading', fn () => $readings->create($P2, 3, ['current_reading' => null]));
expectError($G, 'duplicate (BT1-DIEN đã có trong kỳ) -> VAL-04.03', '_', fn () => $readings->create($P2, 1, ['current_reading' => '1400']), 'Đã có chỉ số');
expectError($G, 'inactive meter (CC2306-NUOC) -> VAL-04.05', '_', fn () => $readings->create($P2, 8, ['current_reading' => '1']), 'không còn hoạt động');
$pdo->exec("UPDATE apartments SET status = 'ngung' WHERE id = 4");
expectError($G, 'inactive apartment (CC-2306) -> VAL-04.05', '_', fn () => $readings->create($P2, 7, ['current_reading' => '1']), 'không còn hoạt động');
$pdo->exec("UPDATE apartments SET status = 'hoat_dong' WHERE id = 4");
expectError($G, 'locked period (06/2024) -> VAL-04.04', '_', fn () => $readings->create($P1, 5, ['current_reading' => '999']), 'Kỳ đã khóa');
expectError($G, 'B7: kỳ trước còn Nháp -> chưa xác định CSĐ (BLOCKED, không lưu)', '_', fn () => $readings->create($P3, 1, ['current_reading' => '1400']), 'B7');
expectOk($G, 'dấu phẩy thập phân "70,5" (CC2305-NUOC 62)', fn () => $readings->create($P2, 6, ['current_reading' => '70,5']));
check($G, 'CL 70,5 − 62 = 8.50 (tính bằng xu, không sai số float)', readingOf($pdo, 6, $P2)['consumption'] === '8.50');
$out = race($testDb, [[KT, 'ky_thuat', 'manual', $P2, 5, '500'], [KT, 'ky_thuat', 'manual', $P2, 5, '510']]);
$ok = array_values(array_filter($out, static fn ($o) => $o['ok']));
check($G, 'concurrent duplicate (2 người cùng nhập CC2305-DIEN): 1 thành công, 1 "Đã có chỉ số"', count($ok) === 1
    && str_contains(implode(' ', array_column($out, 'message')), 'Đã có chỉ số'), json_encode($out, JSON_UNESCAPED_UNICODE));
check($G, 'concurrent duplicate: chỉ 1 bản ghi (UNIQUE meter_id + billing_period_id)', (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE meter_id = 5 AND billing_period_id = ?', [$P2]) === 1);

// ================================================================== EDIT (FN-04.07)
$G = 'EDIT';
$r2 = $readings->find((int) readingOf($pdo, 2, $P2)['id']);
expectOk($G, 'sửa Nháp (230 -> 240) có lý do', fn () => $readings->update((int) $r2['id'], ['current_reading' => '240', 'note' => 'Đọc lại công tơ', 'version_token' => $r2['version_token']]));
$row = readingOf($pdo, 2, $P2);
check($G, 'sửa: CL tính lại 24.00, nguồn Điều chỉnh, lý do lưu vào note, audit UPDATE old/new', $row['consumption'] === '24.00' && $row['source'] === 'dieu_chinh' && $row['note'] === 'Đọc lại công tơ'
    && (function () use ($pdo, $r2): bool {
        $a = $pdo->query("SELECT old_value, new_value FROM audit_logs WHERE action='UPDATE_METER_READING' AND entity_id='{$r2['id']}' ORDER BY id DESC LIMIT 1")->fetch();
        return $a && json_decode($a['old_value'], true)['current_reading'] === '230.00' && json_decode($a['new_value'], true)['current_reading'] === '240.00';
    })());
expectError($G, 'thiếu lý do (VAL-04.15)', 'note', fn () => $readings->update((int) $r2['id'], ['current_reading' => '250', 'note' => '', 'version_token' => $readings->find((int) $r2['id'])['version_token']]));
expectError($G, 'token cũ (người khác vừa sửa) -> từ chối', '_', fn () => $readings->update((int) $r2['id'], ['current_reading' => '250', 'note' => 'x', 'version_token' => $r2['version_token']]), 'người khác');
$err = expectError($G, 'sửa thành CSC < CSĐ -> VAL-04.02 + cờ meter_reset', 'current_reading', fn () => $readings->update((int) $r2['id'], ['current_reading' => '100', 'note' => 'x', 'version_token' => $readings->find((int) $r2['id'])['version_token']]));
$tok = $readings->find((int) $r2['id'])['version_token'];
$out = race($testDb, [[KT, 'ky_thuat', 'edit', $r2['id'], '250', $tok], [KT, 'ky_thuat', 'edit', $r2['id'], '260', $tok]]);
check($G, 'concurrent edit cùng token (cùng giây): 1 thành công, 1 bị từ chối', count(array_filter($out, static fn ($o) => $o['ok'])) === 1
    && str_contains(implode(' ', array_column($out, 'message')), 'người khác'), json_encode($out, JSON_UNESCAPED_UNICODE));

// ================================================================== APPROVAL (FN-04.08)
$G = 'APPROVAL';
actAs(BQL, 'bql');
$rd1 = $readings->find((int) readingOf($pdo, 1, $P2)['id']);
$res = $approvals->approve($P2, [$rd1['id'] => $rd1['version_token']]);
$row = readingOf($pdo, 1, $P2);
check($G, 'approve Draft -> Đã xác nhận, confirmed_by BQL, audit APPROVE_METER_READING', $res['approved'] === [(int) $rd1['id']] && $row['status'] === 'da_xac_nhan'
    && (int) $row['confirmed_by'] === BQL && auditCount($pdo, 'APPROVE_METER_READING', 'meter_reading', (int) $rd1['id']) === 1);
$res = $approvals->approve($P2, [$rd1['id'] => $readings->find((int) $rd1['id'])['version_token']]);
check($G, 'approve already Confirmed -> bỏ qua "Đã được duyệt"', $res['approved'] === [] && str_contains((string) ($res['skipped'][$rd1['id']] ?? ''), 'Đã được duyệt'));
$rd6 = $readings->find((int) readingOf($pdo, 6, $P2)['id']);
$out = race($testDb, [[BQL, 'bql', 'approve', $P2, $rd6['id'], $rd6['version_token']], [BQL, 'bql', 'approve', $P2, $rd6['id'], $rd6['version_token']]]);
check($G, 'concurrent approval cùng chỉ số: 1 duyệt, 1 bỏ qua; 1 audit', count(array_filter($out, static fn ($o) => $o['ok'])) === 1
    && auditCount($pdo, 'APPROVE_METER_READING', 'meter_reading', (int) $rd6['id']) === 1, json_encode($out, JSON_UNESCAPED_UNICODE));
$rd5 = $readings->find((int) readingOf($pdo, 5, $P2)['id']);
actAs(KT, 'ky_thuat');
$readings->update((int) $rd5['id'], ['current_reading' => '505', 'note' => 'đọc lại', 'version_token' => $rd5['version_token']]);
actAs(BQL, 'bql');
$res = $approvals->approve($P2, [$rd5['id'] => $rd5['version_token']]);
check($G, 'approval after reading modified (token cũ) -> bỏ qua, vẫn Nháp', $res['approved'] === [] && str_contains((string) ($res['skipped'][$rd5['id']] ?? ''), 'Đã bị sửa')
    && readingOf($pdo, 5, $P2)['status'] === 'nhap');
$rd2 = $readings->find((int) readingOf($pdo, 2, $P2)['id']);
$res = $approvals->approve($P2, [
    $rd5['id'] => $readings->find((int) $rd5['id'])['version_token'], // hợp lệ
    $rd1['id'] => $readings->find((int) $rd1['id'])['version_token'], // đã duyệt
    $rd2['id'] => $r2['version_token'],                                // token cũ
]);
check($G, 'batch approval partial conflict: duyệt 1, bỏ qua 2 kèm lý do', $res['approved'] === [(int) $rd5['id']] && count($res['skipped']) === 2);
expectError($G, 'approve trong kỳ đã khóa -> từ chối', '_', fn () => $approvals->approve($P1, [1 => 'x']), 'Kỳ đã khóa');

$G = 'EDIT';
actAs(KT, 'ky_thuat');
$rd1 = $readings->find((int) $rd1['id']);
expectOk($G, 'sửa chỉ số Đã xác nhận', fn () => $readings->update((int) $rd1['id'], ['current_reading' => '1350', 'note' => 'BQL yêu cầu đọc lại', 'version_token' => $rd1['version_token']]));
$row = readingOf($pdo, 1, $P2);
check($G, 'Đã xác nhận -> về Nháp, xóa người/thời điểm duyệt (BR-41 [ĐX], ASSUMPTION J-13)', $row['status'] === 'nhap' && $row['confirmed_by'] === null && $row['confirmed_at'] === null && $row['consumption'] === '7.00');

// ================================================================== IMPORT (FN-04.03 / 04.05)
$G = 'IMPORT';
actAs(BQL, 'bql');
$imp = [];
foreach ([1 => [100, 10], 2 => [200, 20], 3 => [300, 30], 4 => [400, 40], 5 => [500, 50]] as $n => [$d, $w]) {
    $aid = $apartments->create(['building_id' => '1', 'zone_id' => '', 'code' => "IMP{$n}", 'area' => '50', 'occupancy_status' => 'co_o']);
    $imp[$n] = ['apt' => $aid,
        'd' => $meters->create(['apartment_id' => (string) $aid, 'meter_code' => "IMP{$n}-D", 'utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => (string) $d]),
        'n' => $meters->create(['apartment_id' => (string) $aid, 'meter_code' => "IMP{$n}-N", 'utility_type' => 'nuoc', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => (string) $w])];
}
actAs(KT, 'ky_thuat');

$file = xlsx([[1, 'IMP1', 100, 150, 50, 10, 15, 5], [2, 'IMP2', 200, 260, 60, 20, 22, 2]], [110, 7]);
$b1 = expectOk($G, 'valid Excel -> upload + kiểm tra -> lô Đã kiểm tra', fn () => upload($P2, $file));
check($G, 'preview: CHƯA ghi meter_readings trước khi xác nhận', (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE import_batch_id = ?', [$b1]) === 0
    && q($pdo, 'SELECT status FROM import_batches WHERE id = ?', [$b1]) === 'da_kiem_tra' && is_file(ReadingImportService::storedPath((int) $b1)));
$pv = $imports->preview((int) $b1);
check($G, 'preview: 2 dòng hợp lệ, 0 lỗi, CSĐ/CSC/CL đúng', $pv['analysis']['valid_rows'] === 2 && $pv['analysis']['error_rows'] === 0
    && $pv['analysis']['rows'][0]['readings'][0]['consumption'] === '50.00' && $pv['analysis']['rows'][1]['readings'][1]['consumption'] === '2.00');
$ctl = json_decode((string) q($pdo, 'SELECT control_total_details FROM import_batches WHERE id = ?', [$b1]), true);
check($G, 'control total match: JSON expected/actual/difference theo chỉ tiêu (electricity/water)', $pv['analysis']['control']['status'] === 'match'
    && $ctl['electricity_consumption']['expected'] == 110 && $ctl['electricity_consumption']['actual'] == 110 && $ctl['electricity_consumption']['difference'] == 0
    && $ctl['water_consumption']['actual'] == 7 && (string) q($pdo, 'SELECT control_total_diff FROM import_batches WHERE id = ?', [$b1]) === '0.00');
$res = expectOk($G, 'confirm import', fn () => $imports->confirm((int) $b1, false));
check($G, 'confirm: lưu 4 chỉ số Nháp, nguồn import, gắn import_batch_id, lô Đã lưu, audit IMPORT_METER_READINGS, xóa file tạm',
    ($res['saved'] ?? 0) === 4 && (int) q($pdo, "SELECT COUNT(*) FROM meter_readings WHERE import_batch_id = ? AND source = 'import' AND status = 'nhap'", [$b1]) === 4
    && q($pdo, 'SELECT status FROM import_batches WHERE id = ?', [$b1]) === 'da_luu' && auditCount($pdo, 'IMPORT_METER_READINGS', 'import_batch', (int) $b1) === 1
    && !is_file(ReadingImportService::storedPath((int) $b1)));
expectError($G, 'confirm lại lô đã lưu -> từ chối', '_', fn () => $imports->confirm((int) $b1, false), 'đã được xác nhận');

expectError($G, 'wrong extension (.csv)', 'file', fn () => upload($P2, $file, 'chi_so.csv'), 'Chỉ chấp nhận file Excel');
$txt = tempnam(sys_get_temp_dir(), 'txt');
file_put_contents($txt, "STT,CH\n1,IMP1\n");
expectError($G, 'wrong MIME (nội dung text, đuôi .xlsx)', 'file', fn () => upload($P2, $txt, 'gia_mao.xlsx'), 'MIME');
$php = tempnam(sys_get_temp_dir(), 'php');
file_put_contents($php, '<?php echo 1;');
expectError($G, 'file .php -> từ chối', 'file', fn () => upload($P2, $php, 'shell.php'));
$h = config('readings.import.headers');
expectError($G, 'missing column (thiếu "Nước CL")', 'file', fn () => upload($P2, xlsx([], null, array_slice($h, 0, 8))), 'thiếu cột Nước CL');
$swapped = $h;
[$swapped[3], $swapped[4]] = [$swapped[4], $swapped[3]];
expectError($G, 'wrong column structure (đổi thứ tự Điện CSĐ/CSC)', 'file', fn () => upload($P2, xlsx([[1, 'IMP3', 300, 330, 30, 30, 31, 1]], null, $swapped)), 'sai thứ tự');
expectError($G, 'file không có dòng dữ liệu', 'file', fn () => upload($P2, xlsx([])), 'không có dòng dữ liệu');

$mixed = xlsx([
    [1, 'IMP3', 300, 330, 30, 30, 31, 1],      // hợp lệ
    [2, 'ZZ9', 1, 2, 1, '', '', ''],             // unknown apartment
    [3, 'IMP4 XX', 400, 410, 10, '', '', ''],    // unknown meter (nhãn XX)
    [4, 'S01', 650, 660, 10, '', '', ''],         // ambiguous meter (S01 có 2 công tơ điện)
    [5, 'IMP3', 300, 340, 40, '', '', ''],        // duplicate inside file
    [6, 'BT1', 1343, 1360, 17, '', '', ''],       // duplicate existing DB (BT1-DIEN đã có chỉ số kỳ 07)
    [7, 'IMP4', 999, 1200, 201, 40, 41, 1],      // CSĐ file khác CSĐ hệ thống (400)
], [9999, 2]);
$b2 = upload($P2, $mixed);
$pv = $imports->preview($b2);
$errText = json_encode(array_merge(...array_column($pv['analysis']['rows'], 'errors')), JSON_UNESCAPED_UNICODE);
check($G, 'mixed valid/invalid: 1 hợp lệ, 6 lỗi', $pv['analysis']['valid_rows'] === 1 && $pv['analysis']['error_rows'] === 6, "valid={$pv['analysis']['valid_rows']} err={$pv['analysis']['error_rows']}");
check($G, 'unknown apartment (VAL-04.12)', str_contains($errText, 'không tìm thấy căn hộ ZZ9'));
check($G, 'unknown meter (nhãn không khớp)', str_contains($errText, 'với nhãn \"XX\"') || str_contains($errText, 'với nhãn "XX"'));
check($G, 'ambiguous meter (VAL-04.13)', str_contains($errText, 'không xác định được công tơ cho căn hộ S01'));
check($G, 'duplicate inside file', str_contains($errText, 'trùng trong file'));
check($G, 'duplicate existing DB (VAL-04.03)', str_contains($errText, 'Đã có chỉ số của công tơ này trong kỳ'));
check($G, 'CSĐ trong file khác CSĐ hệ thống -> lỗi [ASSUMPTION J-12]', str_contains($errText, 'khác CSĐ hệ thống'));
check($G, 'import_errors ghi lỗi theo dòng', (int) q($pdo, 'SELECT COUNT(*) FROM import_errors WHERE import_batch_id = ?', [$b2]) >= 6);
check($G, 'control total mismatch -> cảnh báo MISMATCH (không chặn khi upload)', $pv['analysis']['control']['status'] === 'mismatch'
    && $pv['analysis']['control']['details']['electricity_consumption']['difference'] != 0);
expectError($G, 'mismatch: xác nhận khi chưa tick đã xem chênh lệch -> từ chối (VAL-04.14)', '_', fn () => $imports->confirm($b2, false), 'VAL-04.14');
$res = expectOk($G, 'mismatch: xác nhận có tick -> lưu từng phần (ASSUMPTION A-06/J-12)', fn () => $imports->confirm($b2, true));
check($G, 'lưu từng phần: chỉ 2 chỉ số của dòng hợp lệ (IMP3 điện + nước), 6 dòng lỗi bỏ qua', ($res['saved'] ?? 0) === 2 && ($res['skipped'] ?? 0) === 6
    && (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE import_batch_id = ?', [$b2]) === 2);

$b3 = upload($P2, xlsx([[1, 'IMP4', 400, 420, 20, 40, 44, 4]]));
$pv = $imports->preview($b3);
check($G, 'missing total row -> trạng thái không đối chiếu + cảnh báo, control_total_details NULL', $pv['analysis']['control']['status'] === 'missing'
    && in_array('Không tìm thấy dòng Tổng để đối chiếu.', $pv['analysis']['warnings'], true) && q($pdo, 'SELECT control_total_details FROM import_batches WHERE id = ?', [$b3]) === null);
expectOk($G, 'cancel import', fn () => $imports->cancel($b3));
check($G, 'cancel: lô Đã hủy, không có chỉ số, xóa file, audit CANCEL_IMPORT_BATCH', q($pdo, 'SELECT status FROM import_batches WHERE id = ?', [$b3]) === 'da_huy'
    && (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE import_batch_id = ?', [$b3]) === 0 && !is_file(ReadingImportService::storedPath($b3))
    && auditCount($pdo, 'CANCEL_IMPORT_BATCH', 'import_batch', $b3) === 1);
expectError($G, 'confirm sau khi hủy -> từ chối', '_', fn () => $imports->confirm($b3, false), 'đã hủy');

$b4 = upload($P2, xlsx([[1, 'IMP4', 400, 420, 20, 40, 44, 4]], [20, 4]));
$readings->create($P2, (int) $imp[4]['d'], ['current_reading' => '425']); // nhập tay chen vào trước khi xác nhận
$res = $imports->confirm($b4, false);
check($G, 'manual + import collision: kiểm tra lại khi xác nhận -> dòng IMP4 bị loại, không ghi đè bản nhập tay', $res['saved'] === 0
    && readingOf($pdo, (int) $imp[4]['d'], $P2)['source'] === 'thu_cong' && readingOf($pdo, (int) $imp[4]['n'], $P2) === []
    && str_contains((string) json_encode($imports->preview($b4)['errors'], JSON_UNESCAPED_UNICODE), 'Đã có chỉ số'));

$pdo->exec("UPDATE system_configs SET config_value = '1' WHERE config_key = 'import_strict_mode'");
\App\Services\SystemParameterService::flush(); // STEP 15: tham số đọc 1 lần / request - ghi thẳng DB trong cùng tiến trình thì xoá cache
$b5 = upload($P2, xlsx([[1, 'IMP5', 500, 510, 10, 50, 52, 2], [2, 'ZZ9', 1, 2, 1, '', '', '']]));
expectError($G, 'strict mode (import_strict_mode = 1) + có dòng lỗi -> không lưu gì', '_', fn () => $imports->confirm($b5, false), 'all-or-nothing');
check($G, 'strict mode: 0 chỉ số được lưu, lô vẫn chờ', (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE import_batch_id = ?', [$b5]) === 0);
$pdo->exec("UPDATE system_configs SET config_value = '0' WHERE config_key = 'import_strict_mode'");
\App\Services\SystemParameterService::flush();
$imports->cancel($b5);

$b6 = upload($P2, xlsx([[1, 'IMP5', 500, 510, 10, 50, 52, 2]], [10, 2]));
$out = race($testDb, [[KT, 'ky_thuat', 'confirm', $b6], [KT, 'ky_thuat', 'confirm', $b6]]);
check($G, '2 người xác nhận cùng lô đồng thời: 1 lưu, 1 "đã được xác nhận"; chỉ 2 chỉ số', count(array_filter($out, static fn ($o) => $o['ok'])) === 1
    && (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE import_batch_id = ?', [$b6]) === 2, json_encode($out, JSON_UNESCAPED_UNICODE));

$orphan = (int) q($pdo, "SELECT COUNT(*) FROM meter_readings r LEFT JOIN import_batches b ON b.id = r.import_batch_id WHERE r.source = 'import' AND (r.import_batch_id IS NULL OR b.id IS NULL)");
$manualWithBatch = (int) q($pdo, "SELECT COUNT(*) FROM meter_readings WHERE source = 'thu_cong' AND import_batch_id IS NOT NULL");
check($G, 'import_batch traceability: mọi chỉ số import truy được lô + tên file; nhập tay = NULL', $orphan === 0 && $manualWithBatch === 0
    && (int) q($pdo, "SELECT COUNT(*) FROM meter_readings r JOIN import_batches b ON b.id = r.import_batch_id WHERE r.source = 'import' AND b.file_name = 'chi_so.xlsx'") === 8);

// ================================================================== FN-04.06 / Return - BLOCKED
$G = 'BLOCKED';
check($G, 'FN-04.06: không có đường lưu CSC < CSĐ (is_meter_changed luôn 0)', (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE is_meter_changed = 1 OR current_reading < previous_reading') === 0);
check($G, 'Không tính tiền ở FN-04 (apartment_charges không đổi so với seed)', (int) q($pdo, 'SELECT COUNT(*) FROM apartment_charges') === 4);

$failed = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-9s %s%s\n", $ok ? 'PASS' : 'FAIL', $g, $n, $ok || $d === '' ? '' : "  -> {$d}");
}
printf("\n%d/%d PASS (service test, db=%s)\n", count($results) - $failed, count($results), $testDb);
exit($failed === 0 ? 0 : 1);
