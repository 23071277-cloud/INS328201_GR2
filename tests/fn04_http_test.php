<?php

declare(strict_types=1);

/**
 * STEP 8 - Test HTTP FN-04 qua Apache XAMPP THẬT (APP_URL) trên DB của .env (energy_dss).
 *   php tests/fn04_http_test.php
 * Tạo một kỳ năm 2097 (không chồng kỳ thật), nhập tay, import Excel (multipart), duyệt, tra cứu,
 * kiểm RBAC/CSRF/input sai kiểu; XOÁ toàn bộ dữ liệu E2E ở cuối (audit_logs giữ lại - BR-04).
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/real_db_fixture.php'; // fixture riêng: không phụ thuộc / không sửa dữ liệu thật
restore_error_handler();
restore_exception_handler();

use App\Core\Database;
use App\Support\XlsxWriter;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$startedAt = date('Y-m-d H:i:s');
$month = random_int(1, 12);
$periodCode = sprintf('%02d/2097', $month);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/fn04_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

/** @param array|string $post mảng (urlencoded) hoặc mảng có CURLFile (multipart) */
function http(string $jar, string $method, string $url, $post = [], bool $multipart = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : (is_string($post) ? $post : http_build_query($post)));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size), 'type' => $type];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function login(string $base, string $jar, string $user): bool
{
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');
    $r = http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => $user, 'password' => 'Password@123']);

    return $r['code'] === 302 && str_ends_with($r['location'], '/dashboard');
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

$periodId = 0;
$fx = e2e_fixture_create($pdo, $runId);
$M = $fx['meter'];
$bt1 = $fx['apt_code']['BT1'];
$cc2305 = $fx['apt_code']['CC-2305'];
try {
    // ================================================================ BQL tạo kỳ
    check('PERIOD', 'Đăng nhập bql01', login($base, $jar('bql'), 'bql01'));
    $page = http($jar('bql'), 'GET', $base . '/periods');
    check('PERIOD', 'GET /periods 200 + sidebar nhóm "Vận hành & chỉ số" (mở sẵn)', $page['code'] === 200 && (bool) preg_match('#<div class="nav-group is-open" data-nav-group>\s*<button[^>]*aria-expanded="true"[^>]*>\s*<span class="nav-group__label">Vận hành &amp; chỉ số</span>#u', $page['body']));
    $form = http($jar('bql'), 'GET', $base . '/periods/create');
    $r = http($jar('bql'), 'POST', $base . '/periods', ['csrf_token' => csrfFrom($form['body']), 'month' => (string) $month, 'year' => '2097', 'start_date' => '', 'end_date' => '', 'reading_due_date' => '']);
    $periodId = preg_match('#/periods/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $fx['periods'][] = $periodId;
    check('PERIOD', "BQL tạo kỳ {$periodCode} qua form", $r['code'] === 302 && $periodId > 0 && q($pdo, 'SELECT period_code FROM billing_periods WHERE id = ?', [$periodId]) === $periodCode);
    $r = http($jar('bql'), 'POST', $base . '/periods', ['csrf_token' => csrfFrom($form['body']), 'month' => (string) $month, 'year' => '2097']);
    check('PERIOD', 'Tạo trùng kỳ -> báo "Kỳ này đã được tạo."', str_contains(http($jar('bql'), 'GET', $base . '/periods/create')['body'], 'Kỳ này đã được tạo.'));
    $show = http($jar('bql'), 'GET', $base . "/periods/{$periodId}")['body'];
    check('PERIOD', 'Chi tiết kỳ: danh sách công tơ chờ ghi số + thống kê', str_contains($show, 'Công tơ chờ ghi số') && str_contains($show, $fx['meter_code'][1]));

    // ================================================================ Kỹ thuật nhập tay
    check('READING', 'Đăng nhập kythuat01', login($base, $jar('kt'), 'kythuat01'));
    $entry = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/readings/create?meter_id={$M[1]}");
    // công tơ fixture chưa có chỉ số nào -> CSĐ = chỉ số khởi tạo 1.343 (= CSC 06/2024 của seed)
    check('READING', 'Chọn công tơ -> hiển thị căn hộ, tiện ích, CSĐ chỉ đọc = 1.343 (chỉ số khởi tạo, kỳ đầu)', $entry['code'] === 200
        && str_contains($entry['body'], $fx['building_code'] . ' / ' . $bt1) && str_contains($entry['body'], 'value="1343" readonly') && str_contains($entry['body'], 'Chỉ số khởi tạo của công tơ (kỳ đầu)'));
    $r = http($jar('kt'), 'POST', $base . "/periods/{$periodId}/readings", ['csrf_token' => csrfFrom($entry['body']), 'meter_id' => (string) $M[1], 'current_reading' => '1000']);
    $back = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/readings/create?meter_id={$M[1]}")['body'];
    check('READING', 'CSC < CSĐ -> lỗi VAL-04.02 + hướng dẫn thay/reset công tơ (BLOCKED B5), không lưu', str_contains($back, 'Chỉ số cuối nhỏ hơn chỉ số đầu') && str_contains($back, 'BLOCKED B5')
        && (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ?', [$periodId]) === 0);
    $r = http($jar('kt'), 'POST', $base . "/periods/{$periodId}/readings", ['csrf_token' => csrfFrom($back), 'meter_id' => (string) $M[1], 'current_reading' => ['1400']]);
    check('READING', 'current_reading[] (mảng) -> lỗi validation, không 500', $r['code'] === 302 && str_contains(http($jar('kt'), 'GET', $base . "/periods/{$periodId}/readings/create?meter_id={$M[1]}")['body'], 'phải là số'));
    $r = http($jar('kt'), 'POST', $base . "/periods/{$periodId}/readings", ['csrf_token' => 'invalid', 'meter_id' => (string) $M[1], 'current_reading' => '1400']);
    check('CSRF', 'POST nhập chỉ số với CSRF sai -> 403, không lưu', $r['code'] === 403 && (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ?', [$periodId]) === 0);
    $entry = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/readings/create?meter_id={$M[1]}");
    $r = http($jar('kt'), 'POST', $base . "/periods/{$periodId}/readings", ['csrf_token' => csrfFrom($entry['body']), 'meter_id' => (string) $M[1], 'current_reading' => '1400', 'note' => "E2E {$runId}", 'consumption' => '999999', 'previous_reading' => '0']);
    $saved = $pdo->query("SELECT * FROM meter_readings WHERE meter_id = {$M[1]} AND billing_period_id = {$periodId}")->fetch();
    check('READING', 'Nhập CSC 1.400 -> lưu Nháp, CL = 57 do server tính (bỏ qua consumption/previous gửi giả)', $r['code'] === 302 && $saved
        && $saved['previous_reading'] === '1343.00' && $saved['consumption'] === '57.00' && $saved['status'] === 'nhap' && $saved['import_batch_id'] === null);
    check('READING', 'Thông báo CL sau khi lưu', str_contains(http($jar('kt'), 'GET', $base . "/periods/{$periodId}/readings/create")['body'], 'CL = 57'));

    // ================================================================ Kỹ thuật import Excel
    $tpl = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/imports/template");
    check('IMPORT', 'Tải file mẫu .xlsx của kỳ (200, đúng MIME)', $tpl['code'] === 200 && str_contains($tpl['type'], 'spreadsheetml') && str_starts_with($tpl['body'], 'PK'));
    $create = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/imports/create");
    $txt = tempnam(sys_get_temp_dir(), 'e2e') . '.txt';
    file_put_contents($txt, 'not excel');
    $r = http($jar('kt'), 'POST', $base . "/periods/{$periodId}/imports", ['csrf_token' => csrfFrom($create['body']), 'file' => new CURLFile($txt, 'text/plain', 'chi_so.txt')], true);
    check('IMPORT', 'Upload sai đuôi (.txt) -> từ chối, không tạo lô, không 500', $r['code'] === 302
        && str_contains(http($jar('kt'), 'GET', $base . "/periods/{$periodId}/imports/create")['body'], 'Chỉ chấp nhận file Excel') && (int) q($pdo, 'SELECT COUNT(*) FROM import_batches WHERE billing_period_id = ?', [$periodId]) === 0);
    $xlsx = tempnam(sys_get_temp_dir(), 'e2e') . '.xlsx';
    XlsxWriter::write($xlsx, [
        config('readings.import.headers'),
        [1, $bt1, '', null, null, null, 216, 230, 14],
        [2, $cc2305, '', 450, 480, 30, 62, 70, 8],
        ['Tổng', '', '', null, null, 30, null, null, 22],
    ]);
    $create = http($jar('kt'), 'GET', $base . "/periods/{$periodId}/imports/create");
    $r = http($jar('kt'), 'POST', $base . "/periods/{$periodId}/imports", ['csrf_token' => csrfFrom($create['body']), 'file' => new CURLFile($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "chi_so_{$runId}.xlsx")], true);
    $batchId = preg_match('#/imports/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $preview = http($jar('kt'), 'GET', $base . "/imports/{$batchId}")['body'];
    check('IMPORT', 'Upload hợp lệ -> preview: 2 dòng hợp lệ, MATCH, CL tính, chưa lưu chỉ số', $batchId > 0 && str_contains($preview, 'Xem trước từng dòng')
        && str_contains($preview, '>MATCH<') && str_contains($preview, 'EXPECTED') && (int) q($pdo, 'SELECT COUNT(*) FROM meter_readings WHERE import_batch_id = ?', [$batchId]) === 0);
    $r = http($jar('kt'), 'POST', $base . "/imports/{$batchId}/confirm", ['csrf_token' => csrfFrom($preview)]);
    check('IMPORT', 'Xác nhận -> lưu 3 chỉ số (BT1 nước, CC-2305 điện + nước), gắn import_batch_id', (int) q($pdo, "SELECT COUNT(*) FROM meter_readings WHERE import_batch_id = ? AND source = 'import'", [$batchId]) === 3
        && q($pdo, 'SELECT status FROM import_batches WHERE id = ?', [$batchId]) === 'da_luu');
    check('IMPORT', 'Truy vết: chỉ số import -> lô -> tên file', q($pdo, 'SELECT b.file_name FROM meter_readings r JOIN import_batches b ON b.id = r.import_batch_id WHERE r.billing_period_id = ? LIMIT 1', [$periodId]) === "chi_so_{$runId}.xlsx"
        && !is_file(BASE_PATH . "/storage/imports/batch_{$batchId}.xlsx"));

    // ================================================================ BQL duyệt theo lô + tra cứu
    $appr = http($jar('bql'), 'GET', $base . "/periods/{$periodId}/approvals");
    preg_match_all('/name="reading_ids\[\]" value="(\d+)"/', $appr['body'], $ids);
    preg_match_all('/name="token_(\d+)" value="([0-9a-f]{40})"/', $appr['body'], $toks, PREG_SET_ORDER);
    check('APPROVAL', 'Màn hình duyệt: 4 chỉ số Nháp, mỗi dòng có version token', count($ids[1]) === 4 && count($toks) === 4);
    $post = ['csrf_token' => csrfFrom($appr['body']), 'reading_ids' => $ids[1]];
    foreach ($toks as [, $id, $tok]) {
        $post['token_' . $id] = $tok;
    }
    $r = http($jar('bql'), 'POST', $base . "/periods/{$periodId}/approvals", $post);
    check('APPROVAL', 'BQL duyệt theo lô -> 4 chỉ số Đã xác nhận', $r['code'] === 302 && (int) q($pdo, "SELECT COUNT(*) FROM meter_readings WHERE billing_period_id = ? AND status = 'da_xac_nhan' AND confirmed_by = 1", [$periodId]) === 4);
    $r = http($jar('bql'), 'POST', $base . "/periods/{$periodId}/approvals", $post);
    check('APPROVAL', 'Duyệt lại cùng lô -> bỏ qua "Đã được duyệt trước đó"', str_contains(http($jar('bql'), 'GET', $base . "/periods/{$periodId}/approvals")['body'], 'Đã được duyệt trước đó'));
    $hist = http($jar('bql'), 'GET', $base . "/readings?period_id={$periodId}")['body'];
    check('HISTORY', 'Tra cứu chỉ số kỳ: CSĐ, CSC, CL, trạng thái, nguồn + link lô import', str_contains($hist, 'Hiển thị 1–4 / 4') && str_contains($hist, '/imports/' . $batchId) && str_contains($hist, 'Đã xác nhận'));
    $readingId = (int) q($pdo, 'SELECT id FROM meter_readings WHERE meter_id = ? AND billing_period_id = ?', [$M[1], $periodId]);
    $detail = http($jar('bql'), 'GET', $base . "/readings/{$readingId}")['body'];
    check('HISTORY', 'Chi tiết chỉ số: lịch sử audit (CREATE + APPROVE)', str_contains($detail, 'CREATE_METER_READING') && str_contains($detail, 'APPROVE_METER_READING'));

    // ================================================================ KT sửa bản đã xác nhận -> Nháp
    $edit = http($jar('kt'), 'GET', $base . "/readings/{$readingId}/edit")['body'];
    preg_match('/name="version_token" value="([0-9a-f]{40})"/', $edit, $tok);
    $r = http($jar('kt'), 'POST', $base . "/readings/{$readingId}/update", ['csrf_token' => csrfFrom($edit), 'current_reading' => '1410', 'note' => 'Đọc lại', 'version_token' => $tok[1] ?? '']);
    check('EDIT', 'Kỹ thuật sửa bản Đã xác nhận -> CL 67, về Nháp (ASSUMPTION J-13)', q($pdo, "SELECT CONCAT(consumption, '|', status, '|', source) FROM meter_readings WHERE id = ?", [$readingId]) === '67.00|nhap|dieu_chinh');
    $r = http($jar('kt'), 'POST', $base . "/readings/{$readingId}/update", ['csrf_token' => csrfFrom($edit), 'current_reading' => '1420', 'note' => 'x', 'version_token' => $tok[1] ?? '']);
    check('EDIT', 'Gửi lại với token cũ -> từ chối ghi đè', q($pdo, 'SELECT current_reading FROM meter_readings WHERE id = ?', [$readingId]) === '1410.00');

    // ================================================================ RBAC
    check('RBAC', 'Kỹ thuật GET /periods/create -> 403 (J-10: BQL tạo kỳ)', http($jar('kt'), 'GET', $base . '/periods/create')['code'] === 403);
    check('RBAC', 'Kỹ thuật GET /periods/{id}/approvals -> 403', http($jar('kt'), 'GET', $base . "/periods/{$periodId}/approvals")['code'] === 403);
    check('RBAC', 'Kỹ thuật POST approvals -> 403', http($jar('kt'), 'POST', $base . "/periods/{$periodId}/approvals", ['csrf_token' => csrfFrom($edit), 'reading_ids' => [(string) $readingId]])['code'] === 403
        && q($pdo, 'SELECT status FROM meter_readings WHERE id = ?', [$readingId]) === 'nhap');
    check('RBAC', 'Đăng nhập ketoan01', login($base, $jar('kto'), 'ketoan01'));
    check('RBAC', 'Kế toán xem /periods, /readings (200)', http($jar('kto'), 'GET', $base . '/periods')['code'] === 200 && http($jar('kto'), 'GET', $base . "/readings/{$readingId}")['code'] === 200);
    foreach (["/periods/{$periodId}/readings/create", "/periods/{$periodId}/imports/create", "/readings/{$readingId}/edit", "/periods/{$periodId}/approvals"] as $path) {
        check('RBAC', "Kế toán direct URL {$path} -> 403", http($jar('kto'), 'GET', $base . $path)['code'] === 403);
    }
    check('RBAC', 'BQL GET form nhập chỉ số -> 403 (chỉ Kỹ thuật nhập)', http($jar('bql'), 'GET', $base . "/periods/{$periodId}/readings/create")['code'] === 403);
    check('RBAC', 'Chỉ 3 vai trò: admin01 không đăng nhập được (J-01 đã chốt)', !login($base, $jar('adm'), 'admin01'));

    // ================================================================ AUDIT + LOG
    $s = $pdo->prepare("SELECT action, COUNT(*) FROM audit_logs WHERE created_at >= ? AND action IN ('CREATE_BILLING_PERIOD','CREATE_METER_READING','IMPORT_METER_READINGS','UPDATE_METER_READING','APPROVE_METER_READING') GROUP BY action");
    $s->execute([$startedAt]);
    $seen = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    check('AUDIT', 'Audit: CREATE_BILLING_PERIOD, CREATE/UPDATE/APPROVE_METER_READING, IMPORT_METER_READINGS', ($seen['CREATE_BILLING_PERIOD'] ?? 0) == 1 && ($seen['CREATE_METER_READING'] ?? 0) == 1
        && ($seen['IMPORT_METER_READINGS'] ?? 0) == 1 && ($seen['APPROVE_METER_READING'] ?? 0) == 4 && ($seen['UPDATE_METER_READING'] ?? 0) == 1, json_encode($seen));
    clearstatcache();
    $newLog = (string) file_get_contents($logFile, false, null, $logBefore);
    check('LOG', 'app.log: không có lỗi PHP; CSRF sai được ghi [SECURITY]', !preg_match('/PHP Error|Fatal Error|Uncaught/', $newLog) && str_contains($newLog, '[SECURITY] CSRF token rejected'));
} finally {
    foreach ($fx['periods'] as $pid) {
        foreach ($pdo->query('SELECT id FROM import_batches WHERE billing_period_id = ' . (int) $pid)->fetchAll(PDO::FETCH_COLUMN) as $bid) {
            @unlink(BASE_PATH . "/storage/imports/batch_{$bid}.xlsx");
        }
    }
    e2e_cleanup($pdo, $fx); // chỉ kỳ test + fixture
    $left = e2e_leftovers($pdo, $fx); // theo id kỳ test + id fixture - không đếm kỳ người dùng
    check('CLEANUP', 'Đã xóa dữ liệu E2E (kỳ 2097 + fixture) khỏi DB thật, audit giữ lại', $left === 0, "còn {$left}");
    foreach (['bql', 'kt', 'kto', 'adm'] as $who) {
        @unlink($jar($who));
    }
}

$failed = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-9s %s%s\n", $ok ? 'PASS' : 'FAIL', $g, $n, $ok || $d === '' ? '' : "  -> {$d}");
}
printf("\n%d/%d PASS (HTTP test qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
