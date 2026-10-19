<?php

declare(strict_types=1);

/**
 * STEP 6 - Test HTTP FN-02 qua Apache XAMPP THẬT (APP_URL trong .env, vd
 * http://localhost/energy-dss/public) trên database của .env (energy_dss).
 *
 *   php tests/fn02_http_test.php
 *
 * - Đăng nhập thật bằng 4 tài khoản demo, đi qua Router (base path), auth, RBAC, CSRF.
 * - Tạo dữ liệu có mã tiền tố "E2E" rồi XOÁ ở cuối (kể cả khi test lỗi).
 *   audit_logs sinh ra được GIỮ LẠI (BR-04: không xoá nhật ký).
 * Thoát mã 0 nếu mọi test PASS.
 */

require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = 'E2E' . date('His') . random_int(10, 99);
$auditStartId = (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn(); // lọc audit theo id (created_at chỉ tới giây)
$jarDir = sys_get_temp_dir();

$results = [];
function check(string $group, string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$group, $name, $ok, $detail];
}

/** @return array{code:int,location:string,body:string} */
function http(string $jar, string $method, string $url, array $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $headerSize)];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function hiddenValue(string $html, string $name): string
{
    preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m);

    return html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8');
}

function login(string $base, string $jar, string $username): bool
{
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');
    $r = http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => $username, 'password' => 'Password@123']);

    return $r['code'] === 302 && str_ends_with($r['location'], '/dashboard');
}

/** GET form, gửi POST kèm CSRF (+ updated_at nếu form có). */
function submit(string $base, string $jar, string $formPath, string $postPath, array $fields): array
{
    $form = http($jar, 'GET', $base . $formPath);
    $fields['csrf_token'] = csrfFrom($form['body']);
    if (!isset($fields['updated_at']) && str_contains($form['body'], 'name="updated_at"')) {
        $fields['updated_at'] = hiddenValue($form['body'], 'updated_at');
    }

    return http($jar, 'POST', $base . $postPath, $fields);
}

function idFromLocation(string $location, string $prefix): int
{
    return preg_match('#' . preg_quote($prefix, '#') . '/(\d+)#', $location, $m) ? (int) $m[1] : 0;
}

function dbValue(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

$created = ['residents' => [], 'parking_vehicles_apartment' => [], 'meters' => [], 'apartments' => [], 'zones' => [], 'buildings' => []];
$bql = $jarDir . "/fn02_bql_{$runId}.txt";
$kt = $jarDir . "/fn02_kt_{$runId}.txt";
$kto = $jarDir . "/fn02_kto_{$runId}.txt";
$adm = $jarDir . "/fn02_adm_{$runId}.txt";
$anon = $jarDir . "/fn02_anon_{$runId}.txt";

try {
    // ================================================================ chưa đăng nhập
    $r = http($anon, 'GET', $base . '/buildings');
    check('AUTH', 'Chưa đăng nhập GET /buildings -> 302 /login', $r['code'] === 302 && str_ends_with($r['location'], '/login'), $r['code'] . ' ' . $r['location']);

    // ================================================================ BQL
    check('BQL', 'Đăng nhập bql01 qua Apache', login($base, $bql, 'bql01'));
    $dash = http($bql, 'GET', $base . '/dashboard')['body'];
    $basePath = (string) parse_url($base, PHP_URL_PATH); // Apache: /energy-dss/public; php -S (STEP 4C, DB v2): ''
    check('BQL', 'Sidebar có Danh mục: Tòa nhà, Khu vực, Căn hộ, Công tơ', str_contains($dash, 'nav-group__label">Danh mục</span>')
        && substr_count($dash, $basePath . '/buildings"') + substr_count($dash, $basePath . '/zones"') + substr_count($dash, $basePath . '/apartments"') + substr_count($dash, $basePath . '/meters"') >= 4);

    // --- BUILDING
    $r = submit($base, $bql, '/buildings/create', '/buildings', ['code' => 'CHUNGCU-A', 'name' => "Tên giữ lại {$runId}"]);
    $form = http($bql, 'GET', $base . '/buildings/create')['body'];
    check('BUILDING', 'Duplicate code qua form -> quay lại form, báo lỗi, giữ input', $r['code'] === 302 && str_ends_with($r['location'], '/buildings/create')
        && str_contains($form, 'Mã tòa nhà đã tồn tại.') && str_contains($form, "Tên giữ lại {$runId}"));
    $r = submit($base, $bql, '/buildings/create', '/buildings', ['code' => "{$runId}-B", 'name' => '<b>XSS</b> Tòa E2E']);
    $buildingId = idFromLocation($r['location'], '/buildings');
    $created['buildings'][] = $buildingId;
    check('BUILDING', 'Create -> 302 tới trang chi tiết', $r['code'] === 302 && $buildingId > 0, $r['location']);
    $list = http($bql, 'GET', $base . '/buildings?q=' . $runId)['body'];
    check('BUILDING', 'Search + escape output (XSS hiển thị dạng text)', str_contains($list, "{$runId}-B") && str_contains($list, '&lt;b&gt;XSS&lt;/b&gt;') && !str_contains($list, '<b>XSS</b>'));
    $r = submit($base, $bql, "/buildings/{$buildingId}/edit", "/buildings/{$buildingId}/update", ['code' => "{$runId}-B", 'name' => 'Tòa E2E (đã sửa)']);
    $show = http($bql, 'GET', $base . "/buildings/{$buildingId}")['body'];
    check('BUILDING', 'Edit -> lưu + flash "Đã cập nhật"', $r['code'] === 302 && str_contains($show, 'Tòa E2E (đã sửa)') && str_contains($show, 'Đã cập nhật tòa nhà.'));
    $r = submit($base, $bql, "/buildings/{$buildingId}/edit", "/buildings/{$buildingId}/update", ['code' => "{$runId}-B", 'name' => 'Ghi đè', 'updated_at' => '2000-01-01 00:00:00']);
    $edit = http($bql, 'GET', $base . "/buildings/{$buildingId}/edit")['body'];
    check('BUILDING', 'Edit với updated_at cũ -> từ chối ghi đè', str_contains($edit, 'Dữ liệu đã bị người khác thay đổi') && dbValue($pdo, 'SELECT name FROM buildings WHERE id = ?', [$buildingId]) === 'Tòa E2E (đã sửa)');
    $r = http($bql, 'POST', $base . '/buildings', ['code' => "{$runId}-NOCSRF", 'name' => 'x']);
    check('CSRF', 'BQL POST /buildings thiếu CSRF -> 403, không tạo bản ghi', $r['code'] === 403 && (int) dbValue($pdo, 'SELECT COUNT(*) FROM buildings WHERE code = ?', ["{$runId}-NOCSRF"]) === 0);

    // --- ZONE
    $r = submit($base, $bql, '/zones/create', '/zones', ['building_id' => (string) $buildingId, 'code' => "{$runId}-K", 'name' => 'Khu E2E', 'zone_type' => 'chung_cu']);
    $zoneId = idFromLocation($r['location'], '/zones');
    $created['zones'][] = $zoneId;
    check('ZONE', 'Create khu trong tòa E2E', $r['code'] === 302 && $zoneId > 0, $r['location']);
    $r = submit($base, $bql, '/zones/create', '/zones', ['building_id' => (string) $buildingId, 'code' => "{$runId}-K", 'name' => 'Trùng', 'zone_type' => '']);
    $form = http($bql, 'GET', $base . '/zones/create')['body'];
    check('ZONE', 'Duplicate mã khu trong tòa -> lỗi', str_contains($form, 'Mã khu vực đã tồn tại trong tòa nhà này.'));
    $r = submit($base, $bql, '/zones/create', '/zones', ['building_id' => '99999999', 'code' => "{$runId}-K2", 'name' => 'x', 'zone_type' => '']);
    $form = http($bql, 'GET', $base . '/zones/create')['body'];
    check('ZONE', 'Wrong building (id giả mạo) -> lỗi "Tòa nhà không tồn tại"', str_contains($form, 'Tòa nhà không tồn tại.'));

    // --- APARTMENT
    $r = submit($base, $bql, '/apartments/create', '/apartments', ['building_id' => (string) $buildingId, 'zone_id' => (string) $zoneId, 'code' => "{$runId}-A", 'area' => '0', 'occupancy_status' => 'co_o']);
    $form = http($bql, 'GET', $base . '/apartments/create')['body'];
    check('APARTMENT', 'Area <= 0 -> lỗi VAL-02.02', str_contains($form, 'Diện tích phải là số dương'));
    $r = submit($base, $bql, '/apartments/create', '/apartments', ['building_id' => (string) $buildingId, 'zone_id' => '1', 'code' => "{$runId}-A", 'area' => '55', 'occupancy_status' => 'co_o']);
    $form = http($bql, 'GET', $base . '/apartments/create')['body'];
    check('APARTMENT', 'Zone/building mismatch -> lỗi', str_contains($form, 'Khu vực không thuộc tòa nhà đã chọn.'));
    $r = submit($base, $bql, '/apartments/create', '/apartments', ['building_id' => (string) $buildingId, 'zone_id' => (string) $zoneId, 'code' => "{$runId}-A", 'area' => '55,5', 'occupancy_status' => 'co_o']);
    $apartmentId = idFromLocation($r['location'], '/apartments');
    $created['apartments'][] = $apartmentId;
    check('APARTMENT', 'Create căn hộ (DT 55,5)', $r['code'] === 302 && $apartmentId > 0 && dbValue($pdo, 'SELECT area FROM apartments WHERE id = ?', [$apartmentId]) === '55.50');

    // --- METER
    $r = submit($base, $bql, "/meters/create?apartment_id={$apartmentId}", '/meters', ['apartment_id' => (string) $apartmentId, 'meter_code' => 'BT1-DIEN', 'utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '0']);
    $form = http($bql, 'GET', $base . "/meters/create?apartment_id={$apartmentId}")['body'];
    check('METER', 'Duplicate meter code -> lỗi VAL-02.05', str_contains($form, 'Mã công tơ đã tồn tại.'));
    $r = submit($base, $bql, "/meters/create?apartment_id={$apartmentId}", '/meters', ['apartment_id' => (string) $apartmentId, 'meter_code' => "{$runId}-D", 'utility_type' => 'gas', 'usage_type' => 'sinh_hoat', 'display_label' => '', 'initial_reading' => '-3']);
    $form = http($bql, 'GET', $base . "/meters/create?apartment_id={$apartmentId}")['body'];
    check('METER', 'Invalid utility + negative initial reading -> 2 lỗi', str_contains($form, 'Loại tiện ích không hợp lệ') && str_contains($form, 'Chỉ số khởi tạo phải là số ≥ 0'));
    $r = submit($base, $bql, "/meters/create?apartment_id={$apartmentId}", '/meters', ['apartment_id' => (string) $apartmentId, 'meter_code' => "{$runId}-D", 'utility_type' => 'dien', 'usage_type' => 'kinh_doanh', 'display_label' => 'KD', 'initial_reading' => '120.5']);
    $meterId = idFromLocation($r['location'], '/meters');
    $created['meters'][] = $meterId;
    check('METER', 'Create công tơ điện kinh doanh', $r['code'] === 302 && $meterId > 0);

    // --- OWNER/TENANT + PARKING
    $r = submit($base, $bql, "/apartments/{$apartmentId}/residents/create", "/apartments/{$apartmentId}/residents", ['full_name' => '', 'resident_role' => 'chu_ho', 'phone' => '', 'effective_from' => '', 'effective_to' => '']);
    $form = http($bql, 'GET', $base . "/apartments/{$apartmentId}/residents/create")['body'];
    check('OWNER/TENANT', 'Required: thiếu họ tên -> lỗi', str_contains($form, 'Vui lòng nhập họ tên.'));
    $r = submit($base, $bql, "/apartments/{$apartmentId}/residents/create", "/apartments/{$apartmentId}/residents", ['full_name' => 'Trần Thị E2E', 'resident_role' => 'khach_thue', 'phone' => '0912345678', 'effective_from' => '2024-01-01', 'effective_to' => '']);
    $residentId = (int) dbValue($pdo, 'SELECT MAX(id) FROM residents WHERE apartment_id = ?', [$apartmentId]);
    $created['residents'][] = $residentId;
    check('OWNER/TENANT', 'Create chủ hộ/khách thuê', $r['code'] === 302 && $residentId > 0);
    $r = submit($base, $bql, '/apartments/99999999/residents/create', '/apartments/99999999/residents', ['full_name' => 'x', 'resident_role' => 'chu_ho']);
    check('OWNER/TENANT', 'Apartment invalid (99999999) -> form 404', http($bql, 'GET', $base . '/apartments/99999999/residents/create')['code'] === 404);
    $aptPage = http($bql, 'GET', $base . "/apartments/{$apartmentId}")['body'];
    check('OWNER/TENANT', 'BQL thấy SĐT đầy đủ', str_contains($aptPage, '0912345678'));

    $r = submit($base, $bql, "/apartments/{$apartmentId}/vehicles/edit", "/apartments/{$apartmentId}/vehicles", ['quantity_xe_dap_dien' => '0', 'quantity_xe_may' => '-1', 'quantity_o_to' => '1.5']);
    $form = http($bql, 'GET', $base . "/apartments/{$apartmentId}/vehicles/edit")['body'];
    check('PARKING', 'Negative + non-integer -> lỗi VAL-02.08', substr_count($form, 'Số lượng xe phải là số nguyên ≥ 0.') === 2);
    $r = submit($base, $bql, '/apartments/99999999/vehicles/edit', '/apartments/99999999/vehicles', ['quantity_xe_dap_dien' => '0', 'quantity_xe_may' => '1', 'quantity_o_to' => '0']);
    check('PARKING', 'Invalid apartment -> không lưu', (int) dbValue($pdo, 'SELECT COUNT(*) FROM parking_vehicles WHERE apartment_id = 99999999') === 0);
    $r = submit($base, $bql, "/apartments/{$apartmentId}/vehicles/edit", "/apartments/{$apartmentId}/vehicles", ['quantity_xe_dap_dien' => '1', 'quantity_xe_may' => '2', 'quantity_o_to' => '0']);
    $created['parking_vehicles_apartment'][] = $apartmentId;
    check('PARKING', 'Update hợp lệ -> lưu 1 xe điện, 2 xe máy', $r['code'] === 302
        && (int) dbValue($pdo, "SELECT quantity FROM parking_vehicles WHERE apartment_id = ? AND vehicle_type = 'xe_may'", [$apartmentId]) === 2);

    // ================================================================ KỸ THUẬT (view only)
    check('RBAC-KT', 'Đăng nhập kythuat01', login($base, $kt, 'kythuat01'));
    $page = http($kt, 'GET', $base . '/buildings');
    check('RBAC-KT', 'GET /buildings -> 200, có menu Danh mục, KHÔNG có nút Thêm', $page['code'] === 200 && str_contains($page['body'], 'nav-group__label">Danh mục</span>') && !str_contains($page['body'], '+ Thêm tòa nhà'));
    // Sidebar riêng của Kỹ thuật: đúng thứ tự nhóm / mục (gộp Baseline & mục tiêu + Cảnh báo & khuyến nghị thành "Theo dõi & phân tích"), mục đang mở được highlight
    preg_match('#<aside class="app-sidebar".*?</aside>#s', $page['body'], $ktAside);
    preg_match_all('#nav-group__label">([^<]+)|app-sidebar__icon">[^<]*</span> ([^<]+?)\s*(?:<|$)#u', $ktAside[0] ?? '', $ktM, PREG_SET_ORDER);
    $ktOrder = array_map(static fn ($m) => html_entity_decode(($m[1] ?? '') !== '' ? '#' . $m[1] : trim($m[2])), $ktM);
    check('RBAC-KT', 'Sidebar Kỹ thuật đúng thứ tự: Dashboard / Danh mục / Chỉ số công tơ / Theo dõi & phân tích / Báo cáo; không có Thanh toán, Hộp thư đi, Tính phí, Biểu giá; /buildings đang highlight',
        $ktOrder === ['Dashboard', '#Danh mục', 'Tòa nhà', 'Khu vực', 'Căn hộ', 'Công tơ', '#Chỉ số công tơ', 'Kỳ ghi chỉ số', 'Tra cứu chỉ số',
            '#Theo dõi & phân tích', 'Mục tiêu & theo dõi', 'Cảnh báo', 'Quy tắc ngưỡng', '#Báo cáo', 'Báo cáo tiện ích']
        && (bool) preg_match('#is-active">\s*<span class="app-sidebar__icon">[^<]*</span> Tòa nhà#u', $ktAside[0] ?? ''), implode(' | ', $ktOrder));
    $page = http($kt, 'GET', $base . "/buildings/{$buildingId}");
    check('RBAC-KT', 'GET chi tiết tòa -> 200, không có Sửa/Ngừng', $page['code'] === 200 && !str_contains($page['body'], '/edit"') && !str_contains($page['body'], 'Ngừng tòa nhà'));
    check('RBAC-KT', 'Direct URL GET /buildings/create -> 403', http($kt, 'GET', $base . '/buildings/create')['code'] === 403);
    $csrfKt = csrfFrom(http($kt, 'GET', $base . '/buildings')['body']);
    check('RBAC-KT', 'POST /buildings (CSRF hợp lệ) -> 403', http($kt, 'POST', $base . '/buildings', ['csrf_token' => $csrfKt, 'code' => "{$runId}-KT", 'name' => 'x'])['code'] === 403
        && (int) dbValue($pdo, 'SELECT COUNT(*) FROM buildings WHERE code = ?', ["{$runId}-KT"]) === 0);
    check('RBAC-KT', 'POST /meters/{id}/deactivate -> 403', http($kt, 'POST', $base . "/meters/{$meterId}/deactivate", ['csrf_token' => $csrfKt])['code'] === 403
        && dbValue($pdo, 'SELECT status FROM meters WHERE id = ?', [$meterId]) === 'hoat_dong');
    $aptPage = http($kt, 'GET', $base . "/apartments/{$apartmentId}")['body'];
    check('RBAC-KT', 'Xem căn hộ: SĐT bị che (D10), không có nút thêm chủ hộ', !str_contains($aptPage, '0912345678') && str_contains($aptPage, '••••••5678') && !str_contains($aptPage, '/residents/create'));

    // ================================================================ KẾ TOÁN (view only)
    check('RBAC-KTo', 'Đăng nhập ketoan01', login($base, $kto, 'ketoan01'));
    check('RBAC-KTo', 'GET /meters, /apartments, /zones -> 200', http($kto, 'GET', $base . '/meters')['code'] === 200
        && http($kto, 'GET', $base . '/apartments')['code'] === 200 && http($kto, 'GET', $base . '/zones')['code'] === 200);
    check('RBAC-KTo', 'Direct URL GET /meters/{id}/edit -> 403', http($kto, 'GET', $base . "/meters/{$meterId}/edit")['code'] === 403);
    check('RBAC-KTo', 'Direct URL GET /apartments/{id}/vehicles/edit -> 403 (J-02)', http($kto, 'GET', $base . "/apartments/{$apartmentId}/vehicles/edit")['code'] === 403);
    $csrfKto = csrfFrom(http($kto, 'GET', $base . '/meters')['body']);
    check('RBAC-KTo', 'POST /apartments/{id}/vehicles -> 403, số lượng không đổi', http($kto, 'POST', $base . "/apartments/{$apartmentId}/vehicles", ['csrf_token' => $csrfKto, 'quantity_xe_dap_dien' => '9', 'quantity_xe_may' => '9', 'quantity_o_to' => '9'])['code'] === 403
        && (int) dbValue($pdo, "SELECT quantity FROM parking_vehicles WHERE apartment_id = ? AND vehicle_type = 'xe_may'", [$apartmentId]) === 2);

    // ================================================================ ADMIN (không có quyền FN-02 - J-01)
    check('RBAC-3ROLE', 'Chỉ 3 vai trò: admin01 không đăng nhập được (J-01 đã chốt)', !login($base, $adm, 'admin01'));
    check('RBAC-3ROLE', 'Phiên không đăng nhập: GET /buildings -> /login', http($adm, 'GET', $base . '/buildings')['code'] === 302);

    // ================================================================ BQL: dependency + deactivate chain
    $csrf = csrfFrom(http($bql, 'GET', $base . "/buildings/{$buildingId}")['body']);
    http($bql, 'POST', $base . "/buildings/{$buildingId}/deactivate", ['csrf_token' => $csrf]);
    $show = http($bql, 'GET', $base . "/buildings/{$buildingId}")['body'];
    check('DEPENDENCY', 'Ngừng tòa còn khu/căn hoạt động -> chặn, hiện lý do', str_contains($show, 'Không thể ngừng tòa nhà') && dbValue($pdo, 'SELECT status FROM buildings WHERE id = ?', [$buildingId]) === 'hoat_dong');
    http($bql, 'POST', $base . "/apartments/{$apartmentId}/deactivate", ['csrf_token' => $csrf]);
    check('DEPENDENCY', 'Ngừng căn còn công tơ hoạt động -> chặn', str_contains(http($bql, 'GET', $base . "/apartments/{$apartmentId}")['body'], 'còn 1 công tơ đang hoạt động'));
    http($bql, 'POST', $base . "/residents/{$residentId}/deactivate", ['csrf_token' => $csrf]);
    http($bql, 'POST', $base . "/meters/{$meterId}/deactivate", ['csrf_token' => $csrf]);
    http($bql, 'POST', $base . "/apartments/{$apartmentId}/deactivate", ['csrf_token' => $csrf]);
    http($bql, 'POST', $base . "/zones/{$zoneId}/deactivate", ['csrf_token' => $csrf]);
    http($bql, 'POST', $base . "/buildings/{$buildingId}/deactivate", ['csrf_token' => $csrf]);
    $statuses = [
        (string) dbValue($pdo, 'SELECT is_active FROM residents WHERE id = ?', [$residentId]),
        dbValue($pdo, 'SELECT status FROM meters WHERE id = ?', [$meterId]),
        dbValue($pdo, 'SELECT status FROM apartments WHERE id = ?', [$apartmentId]),
        dbValue($pdo, 'SELECT status FROM zones WHERE id = ?', [$zoneId]),
        dbValue($pdo, 'SELECT status FROM buildings WHERE id = ?', [$buildingId]),
    ];
    check('DEPENDENCY', 'Ngừng đúng thứ tự chủ hộ → công tơ → căn → khu → tòa: tất cả thành công', $statuses === ['0', 'ngung', 'ngung', 'ngung', 'ngung'], json_encode($statuses));
    $r = submit($base, $bql, '/zones/create', '/zones', ['building_id' => (string) $buildingId, 'code' => "{$runId}-K3", 'name' => 'x', 'zone_type' => '']);
    check('ZONE', 'Inactive building -> không tạo khu', str_contains(http($bql, 'GET', $base . '/zones/create')['body'], 'Tòa nhà đã ngừng hoạt động'));

    // ================================================================ AUDIT
    $audit = $pdo->prepare('SELECT action, entity_type, COUNT(*) n FROM audit_logs WHERE id > ? AND user_id = 1
        AND entity_type IN (\'building\',\'zone\',\'apartment\',\'meter\',\'resident\',\'parking_vehicle\') GROUP BY action, entity_type');
    $audit->execute([$auditStartId]);
    $seen = [];
    foreach ($audit->fetchAll() as $row) {
        $seen[$row['entity_type'] . '.' . $row['action']] = (int) $row['n'];
    }
    $needed = ['building.create', 'building.update', 'building.deactivate', 'zone.create', 'zone.deactivate', 'apartment.create', 'apartment.deactivate',
        'meter.create', 'meter.deactivate', 'resident.create', 'resident.deactivate', 'parking_vehicle.update'];
    check('AUDIT', 'Có audit CREATE/UPDATE/DEACTIVATE cho mọi module (actor = bql01)', array_diff($needed, array_keys($seen)) === [], 'thiếu: ' . implode(',', array_diff($needed, array_keys($seen))));
    $oldNew = $pdo->prepare("SELECT old_value, new_value, ip_address FROM audit_logs WHERE action='update' AND entity_type='building' AND entity_id=? ORDER BY id DESC LIMIT 1");
    $oldNew->execute([(string) $buildingId]);
    $row = $oldNew->fetch();
    check('AUDIT', 'Audit update lưu old/new + IP', $row && json_decode((string) $row['old_value'], true)['name'] === '<b>XSS</b> Tòa E2E'
        && json_decode((string) $row['new_value'], true)['name'] === 'Tòa E2E (đã sửa)' && $row['ip_address'] !== null);
    $denied = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action='access_denied' AND id > ?");
    $denied->execute([$auditStartId]);
    check('AUDIT', 'RBAC 403 được ghi access_denied (FR-02)', (int) $denied->fetchColumn() >= 6);
    $logins = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action='login_success' AND id > ?");
    $logins->execute([$auditStartId]);
    check('AUDIT', 'login_success của 3 lần đăng nhập được ghi (FN-01 không bị ảnh hưởng)', (int) $logins->fetchColumn() === 3);
} finally {
    // ---------------------------------------------------------------- dọn dữ liệu E2E (giữ audit_logs)
    $deletes = [
        'residents' => 'DELETE FROM residents WHERE id = ?',
        'parking_vehicles_apartment' => 'DELETE FROM parking_vehicles WHERE apartment_id = ?',
        'meters' => 'DELETE FROM meters WHERE id = ?',
        'apartments' => 'DELETE FROM apartments WHERE id = ?',
        'zones' => 'DELETE FROM zones WHERE id = ?',
        'buildings' => 'DELETE FROM buildings WHERE id = ?',
    ];
    foreach ($deletes as $key => $sql) {
        foreach (array_filter($created[$key]) as $id) {
            $pdo->prepare($sql)->execute([$id]);
        }
    }
    $left = (int) dbValue($pdo, 'SELECT (SELECT COUNT(*) FROM buildings WHERE code LIKE ?) + (SELECT COUNT(*) FROM meters WHERE meter_code LIKE ?)', [$runId . '%', $runId . '%']);
    check('CLEANUP', 'Đã xóa dữ liệu E2E khỏi DB thật (audit_logs giữ lại)', $left === 0);
    foreach ([$bql, $kt, $kto, $adm, $anon] as $jar) {
        @unlink($jar);
    }
}

$failed = 0;
foreach ($results as [$group, $name, $ok, $detail]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-12s %s%s\n", $ok ? 'PASS' : 'FAIL', $group, $name, $ok || $detail === '' ? '' : "  -> {$detail}");
}
printf("\n%d/%d PASS (HTTP test qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
