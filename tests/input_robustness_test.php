<?php

declare(strict_types=1);

/**
 * STEP 8 phần A - hồi quy input sai kiểu (scalar vs array).
 *   php tests/input_robustness_test.php
 * 1) Unit: App\Core\Request với $_POST/$_GET giả lập.
 * 2) HTTP qua Apache THẬT: gửi field dạng mảng (username[], password[], csrf_token[], các field
 *    vô hướng FN-02/FN-03/FN-04, tiers không phải mảng...) -> KHÔNG được 500, app.log không có lỗi mới,
 *    không tạo dữ liệu. (FN-04 được thêm khi có route.)
 */

require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;
use App\Core\Request;

$results = [];
function check(string $group, string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$group, $name, $ok, $detail];
}

// ============================================================ 1) UNIT
$_POST = ['a' => ' x ', 'arr' => ['1'], 'nested' => ['k' => ['v']], 'num' => 5, 'nul' => null, 'pw' => ' p w ',
    'rows' => [['f' => '1', 'g' => ['bad']], 'scalar-row', ['f' => ' 2 ']], 'rowsScalar' => 'abc', 'ids' => ['1', ['x'], '3']];
$_GET = ['q' => ['x'], 'page' => '2'];
check('UNIT', 'postString: vô hướng -> trim', Request::postString('a') === 'x');
check('UNIT', 'postString: mảng -> mặc định, không lỗi', Request::postString('arr', 'D') === 'D' && Request::postString('nested') === '');
check('UNIT', 'postString: thiếu -> mặc định; số -> chuỗi; null -> mặc định', Request::postString('missing', 'M') === 'M' && Request::postString('num') === '5' && Request::postString('nul', 'N') === 'N');
check('UNIT', 'postStringOrNull: mảng/thiếu -> null', Request::postStringOrNull('arr') === null && Request::postStringOrNull('missing') === null);
check('UNIT', 'postRawString: không trim (mật khẩu); mảng -> mặc định', Request::postRawString('pw') === ' p w ' && Request::postRawString('arr') === '');
check('UNIT', 'isMalformed: mảng = true, vô hướng/thiếu = false', Request::isMalformed('arr') && !Request::isMalformed('a') && !Request::isMalformed('missing') && Request::isMalformed('q', 'get'));
check('UNIT', 'queryString: mảng -> mặc định; anyString ưu tiên POST', Request::queryString('q', 'Q') === 'Q' && Request::queryString('page') === '2' && Request::anyString('a') === 'x');
check('UNIT', 'rows (input danh sách): bỏ dòng vô hướng, ô sai kiểu -> ""', Request::rows('rows', ['f', 'g']) === [['f' => '1', 'g' => ''], ['f' => '2', 'g' => '']]);
check('UNIT', 'rows: không phải mảng -> []', Request::rows('rowsScalar', ['f']) === [] && Request::rows('missing', ['f']) === []);
check('UNIT', 'postList: bỏ phần tử sai kiểu', Request::postList('ids') === ['1', '3'] && Request::postList('a') === []);
$_POST = [];
$_GET = [];

// ============================================================ 2) HTTP qua Apache
$base = rtrim((string) config('app.url'), '/');
require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logSizeBefore = is_file($logFile) ? filesize($logFile) : 0;
$countSql = 'SELECT (SELECT COUNT(*) FROM buildings) + (SELECT COUNT(*) FROM zones) + (SELECT COUNT(*) FROM apartments)
    + (SELECT COUNT(*) FROM residents) + (SELECT COUNT(*) FROM meters) + (SELECT COUNT(*) FROM parking_vehicles)
    + (SELECT COUNT(*) FROM tariffs) + (SELECT COUNT(*) FROM tariff_tiers) + (SELECT COUNT(*) FROM fixed_fee_rates)
    + (SELECT COUNT(*) FROM tariff_proposals) + (SELECT COUNT(*) FROM billing_periods) + (SELECT COUNT(*) FROM meter_readings)
    + (SELECT COUNT(*) FROM import_batches)';
$dataBefore = (int) $pdo->query($countSql)->fetchColumn();
$failedBefore = (int) $pdo->query("SELECT SUM(failed_login_count) FROM users")->fetchColumn();
$runId = date('His') . random_int(10, 99);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/robust_{$who}_{$runId}.txt";

/** @param string $body đã encode sẵn (để gửi được field dạng mảng) */
function req(string $jar, string $method, string $url, string $body = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $out];
}

function token(string $jar, string $url): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', req($jar, 'GET', $url)['body'], $m);

    return $m[1] ?? '';
}

function login(string $base, string $jar, string $user): void
{
    @unlink($jar);
    $t = token($jar, $base . '/login');
    req($jar, 'POST', $base . '/login', http_build_query(['csrf_token' => $t, 'username' => $user, 'password' => 'Password@123']));
}

$cases = [];
$t = token($jar('anon'), $base . '/login');
$cases[] = ['FN-01', 'POST /login username[]', req($jar('anon'), 'POST', $base . '/login', http_build_query(['csrf_token' => $t, 'username' => ['bql01'], 'password' => 'Password@123'])), [302]];
$afterMalformed = req($jar('anon'), 'GET', $base . '/login')['body'];
check('FN-01', 'username[]: không đăng nhập, báo VAL-01.01 "Vui lòng nhập tên đăng nhập và mật khẩu."', str_contains($afterMalformed, 'Vui lòng nhập tên đăng nhập và mật khẩu.')
    && req($jar('anon'), 'GET', $base . '/dashboard')['code'] === 302);
$t = token($jar('anon'), $base . '/login');
$cases[] = ['FN-01', 'POST /login password[]', req($jar('anon'), 'POST', $base . '/login', http_build_query(['csrf_token' => $t, 'username' => 'kythuat01', 'password' => ['x']])), [302]];
$cases[] = ['FN-01', 'POST /login csrf_token[] -> 403', req($jar('anon'), 'POST', $base . '/login', http_build_query(['csrf_token' => ['a'], 'username' => 'bql01', 'password' => 'Password@123'])), [403]];

login($base, $jar('bql'), 'bql01');
$dash = req($jar('bql'), 'GET', $base . '/dashboard');
check('FN-01', 'Đăng nhập hợp lệ vẫn hoạt động sau thay đổi (regression)', $dash['code'] === 200);
$cases[] = ['FN-01', 'POST /logout csrf_token[] -> 403 (vẫn còn phiên)', req($jar('bql'), 'POST', $base . '/logout', http_build_query(['csrf_token' => ['x']])), [403]];

$ct = token($jar('bql'), $base . '/buildings/create');
$posts = [
    ['FN-02', '/buildings', ['code' => ['A'], 'name' => 'X']],
    ['FN-02', '/buildings/1/update', ['code' => 'CHUNGCU-A', 'name' => ['Chung cư A'], 'updated_at' => ['2024']]],
    ['FN-02', '/zones', ['building_id' => ['1'], 'code' => 'Z', 'name' => 'Z', 'zone_type' => ['lien_ke']]],
    ['FN-02', '/apartments', ['building_id' => '1', 'zone_id' => ['1'], 'code' => 'Q1', 'area' => ['50'], 'occupancy_status' => 'co_o']],
    ['FN-02', '/meters', ['apartment_id' => '1', 'meter_code' => 'QX', 'utility_type' => ['dien'], 'usage_type' => 'sinh_hoat', 'initial_reading' => ['0']]],
    ['FN-02', '/apartments/1/residents', ['full_name' => ['A'], 'resident_role' => 'chu_ho']],
    ['FN-02', '/apartments/1/vehicles', ['quantity_xe_dap_dien' => ['0'], 'quantity_xe_may' => '1', 'quantity_o_to' => '1']],
    ['FN-03', '/tariffs', ['utility_type' => ['dien'], 'usage_type' => 'sinh_hoat', 'effective_date' => '2099-01-01', 'tiers' => 'not-an-array']],
    ['FN-03', '/tariffs', ['utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'effective_date' => ['2099-01-01'], 'tiers' => ['scalar-row', ['tier_from' => ['0'], 'tier_to' => '', 'unit_price' => ['1']]]]],
    ['FN-03', '/fees', ['fee_type' => 'gui_xe_may', 'unit_price' => ['1'], 'effective_date' => '2099-01-01']],
    ['FN-03', '/tariff-proposals/999999/reject', ['rejection_reason' => ['x']]],
];
foreach ($posts as [$group, $path, $fields]) {
    $cases[] = [$group, "POST {$path} (field dạng mảng)", req($jar('bql'), 'POST', $base . $path, http_build_query($fields + ['csrf_token' => $ct])), [302, 403, 404]];
}
foreach (['/buildings?q[]=x&status[]=y&page[]=2', '/zones?building_id[]=1', '/apartments?zone_id[]=1&occupancy_status[]=co_o', '/meters?utility_type[]=dien&apartment_id[]=1',
             '/tariffs?utility_type[]=dien&lifecycle[]=x&effective_from[]=1', '/tariffs/create?from[]=1', '/fees?fee_type[]=gui_o_to', '/tariff-proposals?status[]=cho_duyet'] as $path) {
    $cases[] = ['GET', "GET {$path}", req($jar('bql'), 'GET', $base . $path), [200]];
}
login($base, $jar('kto'), 'ketoan01');
$kt = token($jar('kto'), $base . '/tariff-proposals');
$cases[] = ['FN-03', 'POST /tariff-proposals tariff_id[] (Kế toán)', req($jar('kto'), 'POST', $base . '/tariff-proposals', http_build_query(['csrf_token' => $kt, 'tariff_id' => ['1'], 'effective_date' => '2099-01-01'])), [302]];

// FN-04 (STEP 8 phần B) - đăng ký thêm ở đây khi route tồn tại
$fn04 = require __DIR__ . '/support/robustness_fn04_cases.php';
foreach ($fn04($base, $jar, 'req', 'token', 'login') as $case) {
    $cases[] = $case;
}

foreach ($cases as [$group, $name, $response, $allowed]) {
    check($group, "{$name} -> {$response['code']} (không 500)", $response['code'] !== 500 && in_array($response['code'], $allowed, true)
        && !str_contains($response['body'], 'Array to string conversion'), "HTTP {$response['code']}");
}

clearstatcache();
$newLog = (string) file_get_contents($logFile, false, null, $logSizeBefore);
$phpErrors = preg_grep('/PHP Error|Fatal Error|Uncaught Exception/', explode(PHP_EOL, $newLog));
check('LOG', 'app.log không có lỗi PHP mới (chỉ có cảnh báo [SECURITY] CSRF hợp lệ)', $phpErrors === [], implode(' | ', $phpErrors));
check('LOG', 'CSRF sai kiểu được ghi [SECURITY] (giám sát)', str_contains($newLog, '[SECURITY] CSRF token rejected'));
check('DATA', 'Không tạo/sửa dữ liệu nghiệp vụ nào', (int) $pdo->query($countSql)->fetchColumn() === $dataBefore);
check('DATA', 'password[] không làm tăng bộ đếm đăng nhập sai', (int) $pdo->query("SELECT SUM(failed_login_count) FROM users")->fetchColumn() === $failedBefore);
foreach (['anon', 'bql', 'kto', 'kt'] as $who) {
    @unlink($jar($who));
}

$failed = 0;
foreach ($results as [$group, $name, $ok, $detail]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-6s %s%s\n", $ok ? 'PASS' : 'FAIL', $group, $name, $ok || $detail === '' ? '' : "  -> {$detail}");
}
printf("\n%d/%d PASS (input robustness, %s)\n", count($results) - $failed, count($results), $base);
exit($failed === 0 ? 0 : 1);
