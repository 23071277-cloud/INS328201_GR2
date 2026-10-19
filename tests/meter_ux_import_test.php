<?php

declare(strict_types=1);

/**
 * UX công tơ (auto-suggest) - kiểm tra FN-04.03 import KHÔNG phụ thuộc nhãn hiển thị:
 * file mẫu mới (cột "Mã công tơ điện/nước", 1 dòng / căn theo mã căn), nhận diện bằng meter_code, tương thích file cũ.
 *   php tests/meter_ux_import_test.php energy_dss_meterux_test
 * Seed: kỳ 07/2024 (#2) mở, 4 căn (BT1, S01, CC-2305, CC-2306), mỗi căn 1 công tơ điện + 1 nước.
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
use App\Services\ReadingImportService;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;

$pdo = Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SESSION = ['user_id' => 2, 'user_role' => 'ky_thuat', 'username' => 'kt', 'full_name' => 'kt'];
$_SERVER['REMOTE_ADDR'] = 'meter-ux-test';
const P = 2;

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

/** Ghi file .xlsx: $rows là mảng dòng đầy đủ (kể cả header). */
function file_of(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'mux') . '.xlsx';
    XlsxWriter::write($path, $rows);

    return $path;
}

/** Upload + xem trước -> [ch => ['codes' => [...], 'errors' => [...]]] */
function analyze(string $path): array
{
    $svc = new ReadingImportService();
    $batch = $svc->upload(P, ['name' => 'mux.xlsx', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)]);
    $pv = $svc->preview($batch);
    $svc->cancel($batch);
    $out = [];
    foreach ($pv['analysis']['rows'] as $row) {
        $out[] = ['ch' => $row['ch'], 'codes' => array_column($row['readings'], 'meter_code'), 'errors' => $row['errors']];
    }

    return $out;
}

$H = config('readings.import.headers');
$codeH = array_values(config('readings.import.meter_code_headers'));

// Nhãn hiển thị kiểu mới (auto-suggest) cho BT1: trước đây sẽ làm file mẫu tách dòng
$pdo->exec("UPDATE meters SET display_label = 'Điện căn BT1' WHERE meter_code = 'BT1-DIEN'");
$pdo->exec("UPDATE meters SET display_label = 'Nước căn BT1' WHERE meter_code = 'BT1-NUOC'");

// =========================================================== File mẫu
$G = 'TEMPLATE';
$tpl = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
(new ReadingImportService())->buildTemplate(P, $tpl);
$rows = XlsxReader::firstSheetRows($tpl, 100);
$rows = array_values(array_filter($rows, static fn ($r) => implode('', array_map('strval', $r)) !== ''));
check($G, '9 cột chuẩn giữ nguyên thứ tự; thêm "Mã công tơ điện", "Mã công tơ nước" ở cuối', array_slice($rows[0], 0, 9) === $H && array_slice($rows[0], 9, 2) === $codeH, json_encode($rows[0], JSON_UNESCAPED_UNICODE));
$data = array_values(array_filter(array_slice($rows, 1), static fn ($r) => ($r[0] ?? '') !== 'Tổng'));
check($G, '1 dòng / căn (4 căn → 4 dòng) dù BT1 có nhãn "Điện căn BT1" / "Nước căn BT1"', count($data) === 4, json_encode(array_column($data, 1)));
$bt1 = array_values(array_filter($data, static fn ($r) => $r[1] === 'BT1'))[0] ?? [];
check($G, 'dòng BT1: CH = "BT1" (không kèm nhãn), mã công tơ BT1-DIEN / BT1-NUOC, CSĐ điền sẵn', ($bt1[9] ?? '') === 'BT1-DIEN' && ($bt1[10] ?? '') === 'BT1-NUOC' && (string) ($bt1[3] ?? '') === '1343', json_encode($bt1, JSON_UNESCAPED_UNICODE));
$pdo->exec("INSERT INTO meters (apartment_id, meter_code, utility_type, usage_type, display_label, initial_reading, status) VALUES (2, 'S01-DIEN-KD', 'dien', 'kinh_doanh', 'KD', 0, 'hoat_dong')");
(new ReadingImportService())->buildTemplate(P, $tpl);
$data = array_values(array_filter(array_slice(XlsxReader::firstSheetRows($tpl, 100), 1), static fn ($r) => ($r[0] ?? '') !== 'Tổng' && implode('', array_map('strval', $r)) !== ''));
$s01 = array_values(array_filter($data, static fn ($r) => $r[1] === 'S01'));
check($G, 'căn có 2 công tơ điện → 2 dòng, mỗi dòng ghi rõ mã (không công tơ nào bị mất)', count($s01) === 2 && in_array('S01-DIEN-KD', array_column($s01, 9), true) && in_array('S01-DIEN', array_column($s01, 9), true), json_encode($s01, JSON_UNESCAPED_UNICODE));

// =========================================================== Nhận diện bằng mã công tơ
$G = 'BY-CODE';
$full = array_merge($H, $codeH);
$r = analyze(file_of([$full,
    [1, 'BT1', '', 1343, 1400, 57, 216, 220, 4, 'BT1-DIEN', 'BT1-NUOC'],
    [2, 'S01', '', 650, 700, 50, 130, 140, 10, 'S01-DIEN', 'S01-NUOC'],
    [3, 'S01', '', 0, 30, 30, null, null, null, 'S01-DIEN-KD', null],
]));
check($G, 'mã công tơ → đúng công tơ (kể cả căn có 2 công tơ điện), không lỗi', $r[0]['codes'] === ['BT1-DIEN', 'BT1-NUOC'] && $r[1]['codes'] === ['S01-DIEN', 'S01-NUOC'] && $r[2]['codes'] === ['S01-DIEN-KD']
    && array_merge(...array_column($r, 'errors')) === [], json_encode($r, JSON_UNESCAPED_UNICODE));
$r = analyze(file_of([array_merge($H, ['Ghi chú'], array_reverse($codeH)),
    [1, 'BT1', '', 1343, 1400, 57, 216, 220, 4, 'x', 'BT1-NUOC', 'BT1-DIEN'],
]));
check($G, 'cột mã nhận theo TÊN, vị trí bất kỳ (đảo thứ tự, có cột lạ xen giữa)', $r[0]['codes'] === ['BT1-DIEN', 'BT1-NUOC'] && $r[0]['errors'] === [], json_encode($r, JSON_UNESCAPED_UNICODE));
$r = analyze(file_of([$full, [1, 'BT1', '', 1343, 1400, 57, null, null, null, 'bt1-dien', null]]));
check($G, 'mã không phân biệt hoa thường', $r[0]['codes'] === ['BT1-DIEN'], json_encode($r, JSON_UNESCAPED_UNICODE));
$r = analyze(file_of([$full,
    [1, 'BT1', '', 1343, 1400, 57, null, null, null, 'KHONG-CO', null],
    [2, 'BT1', '', null, null, null, 216, 220, 4, null, 'BT1-DIEN'],
    [3, 'BT1', '', 1343, 1400, 57, null, null, null, 'S01-DIEN', null],
]));
$errs = json_encode(array_column($r, 'errors'), JSON_UNESCAPED_UNICODE);
check($G, 'mã không tồn tại → lỗi dòng', str_contains($errs, 'không tìm thấy công tơ KHONG-CO'), $errs);
check($G, 'mã công tơ điện đặt ở cột nước → lỗi "không phải công tơ Nước"', str_contains($errs, 'BT1-DIEN không phải công tơ Nước'), $errs);
check($G, 'mã công tơ của căn khác → lỗi (không ghi nhầm căn)', str_contains($errs, 'không tìm thấy công tơ S01-DIEN') && array_merge(...array_column($r, 'codes')) === [], $errs);
$pdo->exec("UPDATE meters SET status = 'ngung' WHERE meter_code = 'S01-DIEN-KD'");
$r = analyze(file_of([$full, [1, 'S01', '', 0, 30, 30, null, null, null, 'S01-DIEN-KD', null]]));
check($G, 'mã công tơ đã ngừng → lỗi', $r[0]['codes'] === [] && $r[0]['errors'] !== [], json_encode($r, JSON_UNESCAPED_UNICODE));
$pdo->exec("UPDATE meters SET status = 'hoat_dong' WHERE meter_code = 'S01-DIEN-KD'");

// =========================================================== Tương thích ngược (file cũ, 9 cột)
$G = 'BACKWARD';
$r = analyze(file_of([$H, [1, 'BT1', '', 1343, 1400, 57, 216, 220, 4]]));
check($G, 'file cũ "BT1" (không cột mã) → vẫn nhận đủ 2 công tơ dù nhãn đã đổi', $r[0]['codes'] === ['BT1-DIEN', 'BT1-NUOC'] && $r[0]['errors'] === [], json_encode($r, JSON_UNESCAPED_UNICODE));
$r = analyze(file_of([$H, [1, 'S01', '', 650, 700, 50, 130, 140, 10]]));
check($G, 'file cũ: căn 2 công tơ điện không hậu tố → lỗi "không xác định được" (như trước), gợi ý cột Mã công tơ', str_contains(json_encode($r[0]['errors'], JSON_UNESCAPED_UNICODE), 'không xác định được công tơ')
    && str_contains(json_encode($r[0]['errors'], JSON_UNESCAPED_UNICODE), 'Mã công tơ'), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = analyze(file_of([$H, [1, 'S01 KD', '', 0, 30, 30, null, null, null]]));
check($G, 'file cũ: hậu tố nhãn "S01 KD" → công tơ KD (như trước)', $r[0]['codes'] === ['S01-DIEN-KD'], json_encode($r, JSON_UNESCAPED_UNICODE));
$r = analyze(file_of([$H, [1, 'S01 S01-DIEN-KD', '', 0, 30, 30, null, null, null]]));
check($G, 'file cũ: hậu tố là mã công tơ → cũng nhận được', $r[0]['codes'] === ['S01-DIEN-KD'], json_encode($r, JSON_UNESCAPED_UNICODE));
$r = analyze(file_of([$full, [1, 'S01 KD', '', 0, 30, 30, null, null, null, '', '']]));
check($G, 'có cột mã nhưng ô trống → dùng cách cũ (hậu tố)', $r[0]['codes'] === ['S01-DIEN-KD'], json_encode($r, JSON_UNESCAPED_UNICODE));
$pdo->exec("UPDATE meters SET status = 'ngung' WHERE meter_code = 'S01-DIEN-KD'"); // trở lại cấu hình seed: mỗi căn 1 công tơ / loại
$demo = BASE_PATH . '/docs/demo/chi_so_07_2024_demo.xlsx';
$r = analyze($demo);
check($G, 'file demo hiện có (docs/demo) vẫn hợp lệ: 3 dòng, 6 chỉ số', count($r) === 3 && count(array_merge(...array_column($r, 'codes'))) === 6 && array_merge(...array_column($r, 'errors')) === [], json_encode($r, JSON_UNESCAPED_UNICODE));
check($G, 'không ghi chỉ số nào (chỉ xem trước rồi huỷ)', (int) $pdo->query('SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ' . P)->fetchColumn() === 0);

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
echo "\n{$pass}/{$total} PASS (service test, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
