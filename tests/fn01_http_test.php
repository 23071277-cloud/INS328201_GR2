<?php

declare(strict_types=1);

/**
 * STEP 15 - Test HTTP FN-01.03 / 01.06 / 01.07 + thu hồi phiên qua Apache XAMPP THẬT (APP_URL) trên DB của .env (energy_dss).
 *   php tests/fn01_http_test.php
 * Tạo tài khoản E2E (e2e_<run>), đăng nhập bằng nó, BQL vô hiệu hoá / đặt lại mật khẩu / đổi vai trò -> phiên E2E bị
 * thu hồi / làm mới ở request kế tiếp; tham số có hiệu lực runtime; tra cứu audit; RBAC 403; CSRF; input sai kiểu;
 * mật khẩu không có trong HTML / log / audit; .env / APP_DEBUG không sửa được. Cuối: XOÁ tài khoản E2E, KHÔI PHỤC tham số.
 */

require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/fn01_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);
$maxAudit = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
$maxUser = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM users')->fetchColumn();
// trạng thái đăng nhập của tài khoản có sẵn (test cố ý đăng nhập sai) - khôi phục ĐÚNG giá trị cũ, không reset hàng loạt
$loginState = $pdo->query("SELECT id, failed_login_count, locked_until FROM users WHERE id <= {$maxUser}")->fetchAll(PDO::FETCH_UNIQUE);
$origParams = $pdo->query('SELECT id, config_key, config_value, updated_by, updated_at FROM system_configs ORDER BY id')->fetchAll();
$seedUsers = $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, username, role, status, password_hash) ORDER BY id) FROM users WHERE id <= {$maxUser}")->fetchColumn();
$envHash = sha1_file(BASE_PATH . '/.env');
$PW1 = 'E2e#Init' . $runId . 'A';
$PW2 = 'E2e#Reset' . $runId . 'B';
$secrets = [$PW1, $PW2, 'weak' . $runId];

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function http(string $jar, string $method, string $url, $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size)];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function tokenFrom(string $html): string
{
    preg_match('/name="version_token" value="([0-9a-f]{40})"/', $html, $m);

    return $m[1] ?? '';
}

function login(string $base, string $jar, string $user, string $pw = 'Password@123'): array
{
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');

    return http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => $user, 'password' => $pw]);
}

function loggedIn(array $r): bool
{
    return $r['code'] === 302 && str_ends_with($r['location'], '/dashboard');
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function phpError(string $body): bool
{
    return (bool) preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)\b.*\.php/i', $body);
}

/** POST form tại $formPath (lấy CSRF + version token từ form). */
function submit(string $base, string $jar, string $formPath, string $action, array $fields, bool $withToken = true): array
{
    $form = http($jar, 'GET', $base . $formPath);
    $data = ['csrf_token' => csrfFrom($form['body'])] + ($withToken ? ['version_token' => tokenFrom($form['body'])] : []) + $fields;

    return http($jar, 'POST', $base . $action, $data);
}

$e2eId = 0;
$evilId = 0;
$htmlSeen = '';
try {
    // 'bql2' = phiên BQL thứ hai (2 người cùng sửa). Hệ thống chỉ có 3 vai trò (FR-01, J-01 đã chốt).
    foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01', 'bql2' => 'bql01'] as $who => $user) {
        check('LOGIN', "Đăng nhập {$user}", loggedIn(login($base, $jar($who), $user)));
    }
    $username = 'e2e_' . $runId;

    // ================================================================ TẠO TÀI KHOẢN
    $G = 'USER-CREATE';
    $r = submit($base, $jar('bql'), '/users/create', '/users', ['username' => $username, 'full_name' => 'E2E <script>alert(1)</script>', 'role' => 'ky_thuat',
        'password' => $secrets[2], 'password_confirmation' => $secrets[2]], false);
    $form = http($jar('bql'), 'GET', $base . '/users/create');
    $htmlSeen .= $form['body'];
    check($G, 'mật khẩu yếu -> quay lại form có lỗi, KHÔNG điền lại mật khẩu, giữ username', $r['code'] === 302 && str_contains($form['body'], 'tối thiểu 8 ký tự')
        && !str_contains($form['body'], $secrets[2]) && str_contains($form['body'], $username));
    $r = submit($base, $jar('bql'), '/users/create', '/users', ['username' => $username, 'full_name' => 'E2E <script>alert(1)</script>', 'role' => 'ky_thuat',
        'password' => $PW1, 'password_confirmation' => $PW1], false);
    $e2eId = preg_match('#/users/(\d+)$#', $r['location'], $m) ? (int) $m[1] : 0;
    check($G, 'BQL tạo tài khoản qua form -> chuyển tới /users/{id}', $e2eId > $maxUser, $r['location']);
    $show = http($jar('bql'), 'GET', $base . "/users/{$e2eId}");
    $htmlSeen .= $show['body'];
    check($G, 'trang chi tiết: họ tên được escape, không lộ hash', str_contains($show['body'], 'E2E &lt;script&gt;') && !str_contains($show['body'], '<script>alert(1)') && !str_contains($show['body'], '$2y$'));
    $list = http($jar('bql'), 'GET', $base . '/users?q=' . urlencode($username) . '&role=ky_thuat&status=hoat_dong');
    check($G, 'danh sách: tìm + lọc vai trò / trạng thái thấy tài khoản mới', $list['code'] === 200 && str_contains($list['body'], $username) && str_contains($list['body'], '1 / 1'));
    $r = submit($base, $jar('bql'), '/users/create', '/users', ['username' => $username, 'full_name' => 'Trùng', 'role' => 'ke_toan', 'password' => $PW1, 'password_confirmation' => $PW1], false);
    $form = http($jar('bql'), 'GET', $base . '/users/create');
    check($G, 'trùng username -> lỗi, không tạo thêm', str_contains($form['body'], 'đã tồn tại') && (int) q($pdo, 'SELECT COUNT(*) FROM users WHERE username = ?', [$username]) === 1);

    // ================================================================ THU HỒI PHIÊN (V5)
    $G = 'SESSION-REVOKE';
    check($G, 'tài khoản E2E đăng nhập được', loggedIn(login($base, $jar('e2e'), $username, $PW1)));
    check($G, 'E2E (Kỹ thuật) mở được trang nhập chỉ số', http($jar('e2e'), 'GET', $base . '/periods/1/readings/create')['code'] === 200);
    // đổi vai trò -> có hiệu lực ngay ở request kế tiếp của phiên đang mở
    $r = submit($base, $jar('bql'), "/users/{$e2eId}/edit", "/users/{$e2eId}/update", ['full_name' => 'E2E Kế toán', 'role' => 'ke_toan']);
    check($G, 'BQL đổi vai trò E2E -> Kế toán', $r['code'] === 302 && q($pdo, 'SELECT role FROM users WHERE id = ?', [$e2eId]) === 'ke_toan');
    $after = http($jar('e2e'), 'GET', $base . '/periods/1/readings/create');
    $dash = http($jar('e2e'), 'GET', $base . '/dashboard');
    check($G, 'phiên E2E: quyền Kỹ thuật mất NGAY (403), header hiện vai trò mới, họ tên mới', $after['code'] === 403 && str_contains($dash['body'], 'Kế toán') && str_contains($dash['body'], 'E2E Kế toán'));
    // vô hiệu hoá -> phiên bị thu hồi
    $r = submit($base, $jar('bql'), "/users/{$e2eId}", "/users/{$e2eId}/status", ['action' => 'deactivate']);
    check($G, 'BQL vô hiệu hóa E2E', $r['code'] === 302 && q($pdo, 'SELECT status FROM users WHERE id = ?', [$e2eId]) === 'ngung');
    $r = http($jar('e2e'), 'GET', $base . '/dashboard');
    check($G, 'request kế tiếp của E2E -> 302 /login (phiên bị thu hồi)', $r['code'] === 302 && str_ends_with($r['location'], '/login'));
    $lp = http($jar('e2e'), 'GET', $base . '/login');
    check($G, 'trang login báo phiên bị thu hồi', str_contains($lp['body'], 'thu hồi'));
    check($G, 'phiên cũ không dùng lại được (vẫn 302 /login)', http($jar('e2e'), 'GET', $base . '/users')['code'] === 302);
    check($G, 'audit session_revoked (status_ngung)', (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'session_revoked' AND entity_id = ? AND new_value LIKE '%status_ngung%'", [$maxAudit, $e2eId]) === 1);
    check($G, 'tài khoản ngung không đăng nhập lại được', !loggedIn(login($base, $jar('e2e'), $username, $PW1)));
    $r = submit($base, $jar('bql'), "/users/{$e2eId}", "/users/{$e2eId}/status", ['action' => 'activate']);
    check($G, 'BQL kích hoạt lại -> đăng nhập được', q($pdo, 'SELECT status FROM users WHERE id = ?', [$e2eId]) === 'hoat_dong' && loggedIn(login($base, $jar('e2e'), $username, $PW1)));
    // đặt lại mật khẩu -> phiên bị thu hồi
    $r = submit($base, $jar('bql'), "/users/{$e2eId}/password", "/users/{$e2eId}/password", ['password' => $PW2, 'password_confirmation' => $PW2]);
    $show = http($jar('bql'), 'GET', $base . "/users/{$e2eId}");
    $htmlSeen .= $show['body'];
    check($G, 'BQL đặt lại mật khẩu E2E', $r['code'] === 302 && str_contains($show['body'], 'Đã đặt lại mật khẩu'));
    $r = http($jar('e2e'), 'GET', $base . '/dashboard');
    check($G, 'đổi mật khẩu -> phiên đang mở của E2E bị thu hồi ở request kế tiếp', $r['code'] === 302 && str_ends_with($r['location'], '/login')
        && (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'session_revoked' AND entity_id = ? AND new_value LIKE '%password_changed%'", [$maxAudit, $e2eId]) === 1);
    check($G, 'mật khẩu cũ bị từ chối, mật khẩu mới đăng nhập được', !loggedIn(login($base, $jar('e2e_old'), $username, $PW1)) && loggedIn(login($base, $jar('e2e'), $username, $PW2)));
    check($G, 'phiên BQL thực hiện thao tác vẫn hợp lệ', http($jar('bql'), 'GET', $base . '/users')['code'] === 200);
    // khoá tạm (sai mật khẩu nhiều lần từ nơi khác) -> phiên đang mở bị thu hồi (V6)
    for ($i = 0; $i < 5; $i++) {
        login($base, $jar('attacker'), $username, 'Wrong#' . $i . 'x');
    }
    check($G, 'sai 5 lần -> khoa_tam', q($pdo, 'SELECT status FROM users WHERE id = ?', [$e2eId]) === 'khoa_tam');
    $r = http($jar('e2e'), 'GET', $base . '/dashboard');
    check($G, 'khóa tạm -> phiên đang mở bị thu hồi (V6 [NEED BA])', $r['code'] === 302 && str_ends_with($r['location'], '/login'));
    $show = http($jar('bql'), 'GET', $base . "/users/{$e2eId}");
    check($G, 'chi tiết hiện nút "Mở khóa" cho tài khoản khóa tạm', str_contains($show['body'], 'value="unlock"'));
    $r = submit($base, $jar('bql'), "/users/{$e2eId}", "/users/{$e2eId}/status", ['action' => 'unlock']);
    check($G, 'BQL mở khóa -> hoat_dong, đăng nhập được', q($pdo, 'SELECT status FROM users WHERE id = ?', [$e2eId]) === 'hoat_dong' && loggedIn(login($base, $jar('e2e'), $username, $PW2)));

    // ================================================================ TỰ BẢO VỆ
    $G = 'SELF-PROTECT';
    $show = http($jar('bql'), 'GET', $base . '/users/1');
    check($G, 'trang của chính mình: không có nút đổi trạng thái', !str_contains($show['body'], '/users/1/status'));
    $r = http($jar('bql'), 'POST', $base . '/users/1/status', ['csrf_token' => csrfFrom($show['body']), 'version_token' => tokenFrom($show['body']), 'action' => 'deactivate']);
    $after = http($jar('bql'), 'GET', $base . '/users/1');
    check($G, 'POST thẳng tự vô hiệu hóa -> từ chối, bql01 vẫn hoạt động', str_contains($after['body'], 'chính mình') && q($pdo, 'SELECT status FROM users WHERE id = 1') === 'hoat_dong');
    $ed = http($jar('bql'), 'GET', $base . '/users/1/edit');
    $r = http($jar('bql'), 'POST', $base . '/users/1/update', ['csrf_token' => csrfFrom($ed['body']), 'version_token' => tokenFrom($ed['body']), 'full_name' => q($pdo, 'SELECT full_name FROM users WHERE id = 1'), 'role' => 'ke_toan']);
    check($G, 'POST thẳng tự đổi vai trò -> từ chối, bql01 vẫn là BQL', q($pdo, 'SELECT role FROM users WHERE id = 1') === 'bql');

    // ================================================================ RBAC
    $G = 'RBAC';
    $form = http($jar('bql'), 'GET', $base . '/users');
    $routes = [['GET', '/users'], ['GET', '/users/create'], ['GET', "/users/{$e2eId}"], ['GET', "/users/{$e2eId}/edit"], ['GET', "/users/{$e2eId}/password"],
        ['POST', '/users'], ['POST', "/users/{$e2eId}/update"], ['POST', "/users/{$e2eId}/status"], ['POST', "/users/{$e2eId}/password"],
        ['GET', '/audit-logs'], ['GET', '/audit-logs/1']];
    foreach (['kt' => 'Kỹ thuật', 'kto' => 'Kế toán'] as $who => $label) {
        $codes = [];
        foreach ($routes as [$method, $path]) {
            $pg = http($jar($who), 'GET', $base . '/dashboard');
            $codes[] = http($jar($who), $method, $base . $path, $method === 'POST' ? ['csrf_token' => csrfFrom($pg['body']), 'action' => 'deactivate', 'role' => 'bql',
                'username' => 'hack' . $runId, 'full_name' => 'x', 'password' => $PW1, 'password_confirmation' => $PW1] : [])['code'];
        }
        check($G, "{$label}: mọi route tài khoản / audit -> 403", array_unique($codes) === [403], json_encode($codes));
    }
    check($G, 'không tạo được tài khoản "hack" nào', (int) q($pdo, 'SELECT COUNT(*) FROM users WHERE username = ?', ['hack' . $runId]) === 0);
    check($G, 'Kỹ thuật / Kế toán: /system-parameters -> 403', http($jar('kt'), 'GET', $base . '/system-parameters')['code'] === 403 && http($jar('kto'), 'GET', $base . '/system-parameters/1/edit')['code'] === 403);
    check($G, 'Chỉ 3 vai trò: admin01 không còn, đăng nhập admin01 thất bại', (int) q($pdo, "SELECT COUNT(*) FROM users WHERE username = 'admin01'") === 0
        && !loggedIn(login($base, $jar('adm'), 'admin01')));
    $r = submit($base, $jar('bql'), '/users/create', '/users', ['username' => 'adm' . $runId, 'full_name' => 'Thử admin', 'role' => 'admin', 'password' => $PW1, 'password_confirmation' => $PW1], false);
    $form = http($jar('bql'), 'GET', $base . '/users/create');
    check($G, 'Tạo tài khoản role = admin -> "Vai trò không hợp lệ", không tạo; dropdown chỉ 3 vai trò', str_contains($form['body'], 'Vai trò không hợp lệ')
        && (int) q($pdo, 'SELECT COUNT(*) FROM users WHERE username = ?', ['adm' . $runId]) === 0
        && preg_match_all('#<option value="(bql|ky_thuat|ke_toan|admin)"#', $form['body'], $opt) === 3 && !in_array('admin', $opt[1], true));
    foreach (['/users', '/audit-logs', '/system-parameters', "/users/{$e2eId}"] as $p) {
        $r = http($jar('anon'), 'GET', $base . $p);
        check($G, "Chưa đăng nhập {$p} -> 302 /login", $r['code'] === 302 && str_ends_with($r['location'], '/login'));
    }
    $sb = http($jar('bql'), 'GET', $base . '/dashboard')['body'];
    $sk = http($jar('kt'), 'GET', $base . '/dashboard')['body'];
    check($G, 'sidebar: BQL thấy 3 link thật; Kỹ thuật không thấy', str_contains($sb, 'href="' . $base . '/users"') && str_contains($sb, '/audit-logs"') && str_contains($sb, '/system-parameters"')
        && !str_contains($sk, '/users"') && !str_contains($sk, '/audit-logs"') && !str_contains($sk, '/system-parameters"'));
    $r = http($jar('bql'), 'POST', $base . "/users/{$e2eId}/status", ['action' => 'deactivate', 'version_token' => 'x']);
    check($G, 'POST không có CSRF -> 403, trạng thái không đổi', $r['code'] === 403 && q($pdo, 'SELECT status FROM users WHERE id = ?', [$e2eId]) === 'hoat_dong');
    foreach (['/users/abc', '/users/999999', '/audit-logs/abc', '/audit-logs/999999999', '/system-parameters/999/edit', '/users/0/edit'] as $p) {
        check($G, "{$p} -> 404", http($jar('bql'), 'GET', $base . $p)['code'] === 404);
    }

    // ================================================================ INPUT SAI KIỂU
    $G = 'MALFORMED';
    $before = q($pdo, 'SELECT CONCAT(full_name, role, status, password_hash) FROM users WHERE id = ?', [$e2eId]);
    $cf = csrfFrom(http($jar('bql'), 'GET', $base . '/users/create')['body']);
    $cases = [
        ['/users', 'csrf_token=' . urlencode($cf) . '&username%5B%5D=a&full_name=x&role=ky_thuat&password=' . urlencode($PW1) . '&password_confirmation=' . urlencode($PW1)],
        ['/users', 'csrf_token=' . urlencode($cf) . '&username=mal' . $runId . '&full_name=x&role%5B%5D=bql&password=' . urlencode($PW1) . '&password_confirmation=' . urlencode($PW1)],
        ['/users', 'csrf_token=' . urlencode($cf) . '&username=mal' . $runId . '&full_name%5Bx%5D=1&role=ky_thuat&password%5B%5D=' . urlencode($PW1) . '&password_confirmation%5B%5D=' . urlencode($PW1)],
        ["/users/{$e2eId}/update", 'csrf_token=' . urlencode($cf) . '&version_token%5B%5D=1&full_name=x&role%5B%5D=bql'],
        ["/users/{$e2eId}/status", 'csrf_token=' . urlencode($cf) . '&version_token=x&action%5B%5D=deactivate&status%5B%5D=ngung'],
        ["/users/{$e2eId}/password", 'csrf_token=' . urlencode($cf) . '&version_token=x&password%5B%5D=a&password_confirmation%5B%5D=a'],
        ['/system-parameters/1/update', 'csrf_token=' . urlencode($cf) . '&version_token=x&config_value%5B%5D=99&parameter_value%5B%5D=99'],
        ['/users', 'csrf_token%5B%5D=' . urlencode($cf) . '&username=mal' . $runId],
    ];
    $bad = [];
    foreach ($cases as [$path, $body]) {
        $r = http($jar('bql'), 'POST', $base . $path, $body);
        $follow = $r['location'] !== '' ? http($jar('bql'), 'GET', $r['location']) : $r;
        if (!in_array($r['code'], [302, 403], true) || phpError($r['body'] . $follow['body']) || $follow['code'] >= 500) {
            $bad[] = $path . ':' . $r['code'];
        }
    }
    check($G, 'username[] / role[] / full_name[x] / password[] / action[] / status[] / version_token[] / config_value[] / parameter_value[] / csrf_token[] -> không 500, không warning', $bad === [], implode(', ', $bad));
    check($G, 'không tạo tài khoản mal*, tài khoản E2E không đổi', (int) q($pdo, 'SELECT COUNT(*) FROM users WHERE username = ?', ['mal' . $runId]) === 0
        && q($pdo, 'SELECT CONCAT(full_name, role, status, password_hash) FROM users WHERE id = ?', [$e2eId]) === $before);
    check($G, 'tham số #1 không đổi', q($pdo, 'SELECT config_value FROM system_configs WHERE id = 1') === $origParams[0]['config_value']);
    $codes = [];
    foreach (['/users?role%5B%5D=bql&status%5B%5D=x&q%5B%5D=1', '/audit-logs?action%5B%5D=x&user_id%5B%5D=1&date_from%5B%5D=1&ip_address%5B%5D=1&entity_id%5Bx%5D=1', '/audit-logs?page%5B%5D=2', '/users?page=-5'] as $p) {
        $r = http($jar('bql'), 'GET', $base . $p);
        $codes[] = $r['code'] . (phpError($r['body']) ? 'ERR' : '');
    }
    check($G, 'bộ lọc GET dạng mảng -> 200, không warning', array_unique($codes) === ['200'], json_encode($codes));

    // ================================================================ THAM SỐ HỆ THỐNG
    $G = 'PARAM';
    $idx = array_column($origParams, 'id', 'config_key');
    $page = http($jar('bql'), 'GET', $base . '/system-parameters');
    preg_match_all('#<td><code>([^<]+)</code></td>#', $page['body'], $keys);
    check($G, 'danh sách tham số: đúng 5 khóa whitelist (không có dòng APP_DEBUG / DB_* / APP_KEY / đường dẫn), 5 link sửa', $keys[1] === ['session_timeout_minutes', 'max_failed_login_attempts',
        'account_lock_duration_minutes', 'bank_account_info', 'import_strict_mode'] && substr_count($page['body'], '/edit"') === 5 && !preg_match('#name="(APP_|DB_)#', $page['body']), json_encode($keys[1]));
    $r = submit($base, $jar('bql'), "/system-parameters/{$idx['max_failed_login_attempts']}/edit", "/system-parameters/{$idx['max_failed_login_attempts']}/update", ['config_value' => '99']);
    $form = http($jar('bql'), 'GET', $base . "/system-parameters/{$idx['max_failed_login_attempts']}/edit");
    check($G, 'giá trị ngoài khoảng (99) -> lỗi trên form, không lưu', str_contains($form['body'], 'trong khoảng 3 – 20') && q($pdo, 'SELECT config_value FROM system_configs WHERE id = ?', [$idx['max_failed_login_attempts']]) === '5');
    $r = submit($base, $jar('bql'), "/system-parameters/{$idx['max_failed_login_attempts']}/edit", "/system-parameters/{$idx['max_failed_login_attempts']}/update", ['config_value' => '3', 'config_key' => 'APP_DEBUG']);
    check($G, 'BQL đổi max_failed_login_attempts = 3 (trường config_key lạ bị bỏ qua)', $r['code'] === 302 && q($pdo, 'SELECT config_value FROM system_configs WHERE id = ?', [$idx['max_failed_login_attempts']]) === '3'
        && q($pdo, 'SELECT config_key FROM system_configs WHERE id = ?', [$idx['max_failed_login_attempts']]) === 'max_failed_login_attempts');
    for ($i = 0; $i < 3; $i++) {
        login($base, $jar('attacker'), $username, 'Wrong#' . $i . 'y');
    }
    check($G, 'có hiệu lực runtime (không restart Apache): sai 3 lần -> khoa_tam', q($pdo, 'SELECT status FROM users WHERE id = ?', [$e2eId]) === 'khoa_tam');
    $pdo->exec("UPDATE users SET status = 'hoat_dong', failed_login_count = 0, locked_until = NULL WHERE id = {$e2eId}");
    $r = submit($base, $jar('bql2'), "/system-parameters/{$idx['bank_account_info']}/edit", "/system-parameters/{$idx['bank_account_info']}/update", ['config_value' => "TK E2E {$runId} <b>x</b>"]);
    $page = http($jar('bql2'), 'GET', $base . '/system-parameters');
    check($G, 'BQL sửa bank_account_info; hiển thị escape', $r['code'] === 302 && str_contains($page['body'], "TK E2E {$runId} &lt;b&gt;x&lt;/b&gt;"));
    $r = submit($base, $jar('bql'), "/system-parameters/{$idx['session_timeout_minutes']}/edit", "/system-parameters/{$idx['session_timeout_minutes']}/update", ['config_value' => '5']);
    check($G, 'session_timeout_minutes = 5 -> lưu', q($pdo, 'SELECT config_value FROM system_configs WHERE id = ?', [$idx['session_timeout_minutes']]) === '5');
    // phiên E2E: last_activity lùi 6 phút -> hết hạn theo tham số MỚI (5) dù .env = 30
    login($base, $jar('e2e'), $username, $PW2);
    $stale = submit($base, $jar('bql'), "/system-parameters/{$idx['session_timeout_minutes']}/edit", "/system-parameters/{$idx['session_timeout_minutes']}/update", ['config_value' => '5']);
    $form1 = http($jar('bql'), 'GET', $base . "/system-parameters/{$idx['session_timeout_minutes']}/edit");
    $form2 = http($jar('bql2'), 'GET', $base . "/system-parameters/{$idx['session_timeout_minutes']}/edit");
    http($jar('bql'), 'POST', $base . "/system-parameters/{$idx['session_timeout_minutes']}/update", ['csrf_token' => csrfFrom($form1['body']), 'version_token' => tokenFrom($form1['body']), 'config_value' => '6']);
    http($jar('bql2'), 'POST', $base . "/system-parameters/{$idx['session_timeout_minutes']}/update", ['csrf_token' => csrfFrom($form2['body']), 'version_token' => tokenFrom($form2['body']), 'config_value' => '7']);
    $form2b = http($jar('bql2'), 'GET', $base . "/system-parameters/{$idx['session_timeout_minutes']}/edit");
    check($G, '2 người mở cùng form tham số: người lưu sau bị từ chối (token)', q($pdo, 'SELECT config_value FROM system_configs WHERE id = ?', [$idx['session_timeout_minutes']]) === '6' && str_contains($form2b['body'], 'người khác'));
    // tham số ngoài whitelist trong bảng -> 404
    $pdo->exec("INSERT INTO system_configs (config_key, config_value, description) VALUES ('app_debug_e2e_{$runId}', 'false', 'E2E')");
    $evilId = (int) $pdo->lastInsertId();
    $pg = http($jar('bql'), 'GET', $base . '/system-parameters');
    check($G, 'khóa ngoài whitelist: không hiện, GET edit 404, POST update 404, giá trị không đổi', !str_contains($pg['body'], "app_debug_e2e_{$runId}")
        && http($jar('bql'), 'GET', $base . "/system-parameters/{$evilId}/edit")['code'] === 404
        && http($jar('bql'), 'POST', $base . "/system-parameters/{$evilId}/update", ['csrf_token' => csrfFrom($pg['body']), 'version_token' => 'x', 'config_value' => 'true'])['code'] === 404
        && q($pdo, 'SELECT config_value FROM system_configs WHERE id = ?', [$evilId]) === 'false');
    check($G, '.env không bị sửa (sha1 không đổi), không có route nào ghi .env', sha1_file(BASE_PATH . '/.env') === $envHash);
    // Apache: chặn bằng .htaccess -> 403. Máy chủ test php -S (STEP 4C, DB v2) chỉ phục vụ public/ -> .env không tồn tại ở đó (404), không lộ nội dung.
    $isApache = str_contains($base, '/energy-dss/public');
    $envA = http($jar('anon'), 'GET', $base . '/.env');
    $envB = http($jar('anon'), 'GET', preg_replace('#/public$#', '', $base) . '/.env');
    check($G, '/.env không tải được qua web', $isApache ? ($envA['code'] === 403 && $envB['code'] === 403)
        : (in_array($envA['code'], [403, 404], true) && in_array($envB['code'], [403, 404], true) && !preg_match('/DB_PASSWORD|MAIL_PASSWORD|APP_KEY/', $envA['body'] . $envB['body'])));

    // ================================================================ TRA CỨU AUDIT
    $G = 'AUDIT-SEARCH';
    foreach (['CREATE_USER', 'UPDATE_USER', 'CHANGE_USER_STATUS', 'RESET_USER_PASSWORD', 'UPDATE_SYSTEM_PARAMETER', 'session_revoked'] as $action) {
        $isParam = $action === 'UPDATE_SYSTEM_PARAMETER';
        $url = '/audit-logs?action=' . $action . ($isParam ? '' : '&entity_type=users&entity_id=' . $e2eId) . '&date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d');
        $pg = http($jar('bql'), 'GET', $base . $url);
        $htmlSeen .= $pg['body'];
        check($G, "tra cứu thấy {$action} vừa tạo", $pg['code'] === 200 && preg_match_all('#href="[^"]*/audit-logs/(\d+)"#', $pg['body'], $mm) > 0 && max(array_map('intval', $mm[1])) > $maxAudit);
    }
    $id = (int) q($pdo, "SELECT MAX(id) FROM audit_logs WHERE action = 'RESET_USER_PASSWORD' AND entity_id = ?", [$e2eId]);
    $pg = http($jar('bql'), 'GET', $base . "/audit-logs/{$id}");
    $htmlSeen .= $pg['body'];
    check($G, 'chi tiết audit: có người thực hiện, IP, giá trị mới (không mật khẩu)', $pg['code'] === 200 && str_contains($pg['body'], 'bql01') && str_contains($pg['body'], 'password_reset'));
    $pg = http($jar('bql'), 'GET', $base . '/audit-logs?user_id=1&ip_address=127.0.0.1');
    check($G, 'lọc người thực hiện + IP -> 200', $pg['code'] === 200 && !phpError($pg['body']));
    $pg = http($jar('bql'), 'GET', $base . '/audit-logs?date_from=2026-12-31&date_to=2026-01-01');
    check($G, 'từ ngày > đến ngày -> thông báo lỗi, không truy vấn', str_contains($pg['body'], 'nhỏ hơn hoặc bằng'));
    $pg = http($jar('bql'), 'GET', $base . '/audit-logs?entity_type=khong_co_' . $runId);
    check($G, 'không có bản ghi -> "Không có dữ liệu phù hợp"', str_contains($pg['body'], 'Không có dữ liệu phù hợp'));
    $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, created_at) VALUES (1, 'E2E_MASK', 'e2e', ?, ?, NULL, '127.0.0.1', NOW())")
        ->execute([$runId, json_encode(['password' => 'Leak' . $runId, 'csrf_token' => 'Csrf' . $runId])]);
    $pg = http($jar('bql'), 'GET', $base . '/audit-logs?action=E2E_MASK&entity_id=' . $runId);
    check($G, 'giá trị nhạy cảm bị che *** trên màn hình', str_contains($pg['body'], '***') && !str_contains($pg['body'], 'Leak' . $runId) && !str_contains($pg['body'], 'Csrf' . $runId));
    $r = http($jar('bql'), 'POST', $base . '/audit-logs/1', ['csrf_token' => csrfFrom($pg['body'])]);
    check($G, 'không có route sửa / xóa log (POST /audit-logs/1 -> 404/405)', in_array($r['code'], [404, 405], true));
    $user = http($jar('bql'), 'GET', $base . "/users/{$e2eId}");
    check($G, 'trang chi tiết tài khoản có nhật ký gần đây', str_contains($user['body'], 'CHANGE_USER_STATUS') && str_contains($user['body'], 'RESET_USER_PASSWORD'));

    // ================================================================ BẢO MẬT MẬT KHẨU
    $G = 'SECRETS';
    $leaks = array_filter($secrets, static fn ($s) => str_contains($htmlSeen, $s));
    check($G, 'mật khẩu không xuất hiện trong HTML đã nhận', $leaks === [], implode(',', $leaks));
    $auditText = (string) q($pdo, "SELECT GROUP_CONCAT(CONCAT(IFNULL(old_value,''), IFNULL(new_value,''))) FROM audit_logs WHERE id > ?", [$maxAudit]);
    check($G, 'mật khẩu / hash không có trong audit_logs', array_filter($secrets, static fn ($s) => str_contains($auditText, $s)) === [] && !str_contains($auditText, '$2y$'));
    clearstatcache();
    $newLog = (string) file_get_contents($logFile, false, null, $logBefore);
    check($G, 'mật khẩu không có trong app.log', array_filter($secrets, static fn ($s) => str_contains($newLog, $s)) === []);
    check($G, 'app.log: không có lỗi PHP / Uncaught', !preg_match('/PHP Error|Fatal Error|Uncaught/i', $newLog), substr($newLog, 0, 300));
    $assets = http($jar('anon'), 'GET', $base . '/assets/');
    check($G, '/assets/ không liệt kê thư mục (Options -Indexes)', str_contains($base, '/energy-dss/public') ? $assets['code'] === 403
        : (in_array($assets['code'], [403, 404], true) && !str_contains($assets['body'], 'Index of') && !preg_match('#href="(css|js|img)/"#', $assets['body']))); // php -S: không liệt kê thư mục (trang 404 của app có <link> app.css là bình thường)
} finally {
    // khôi phục tham số + xoá dữ liệu E2E (audit giữ lại, user_id của tài khoản E2E -> NULL)
    $st = $pdo->prepare('UPDATE system_configs SET config_value = ?, updated_by = ?, updated_at = ? WHERE id = ?');
    foreach ($origParams as $p) {
        $st->execute([$p['config_value'], $p['updated_by'], $p['updated_at'], $p['id']]);
    }
    if ($evilId > 0) {
        $pdo->exec("DELETE FROM system_configs WHERE id = {$evilId}");
    }
    // chỉ tài khoản do lượt chạy tạo (e2e_/hack/adm/mal + mã lượt chạy) - không đụng tài khoản người dùng tạo trong lúc test chạy
    $st = $pdo->prepare('SELECT id FROM users WHERE id > ? AND username LIKE ?');
    $st->execute([$maxUser, '%' . $runId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($ids as $id) {
        $pdo->prepare('UPDATE audit_logs SET user_id = NULL WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    $restore = $pdo->prepare('UPDATE users SET failed_login_count = ?, locked_until = ? WHERE id = ? AND (failed_login_count <> ? OR NOT (locked_until <=> ?))');
    foreach ($loginState as $uid => $st0) {
        $restore->execute([$st0['failed_login_count'], $st0['locked_until'], $uid, $st0['failed_login_count'], $st0['locked_until']]);
    }
    foreach (['bql', 'kt', 'kto', 'bql2', 'adm', 'e2e', 'e2e_old', 'attacker', 'anon'] as $who) {
        @unlink($jar($who));
    }
    $paramsNow = $pdo->query('SELECT id, config_key, config_value, updated_by, updated_at FROM system_configs ORDER BY id')->fetchAll();
    check('CLEANUP', 'Đã xóa tài khoản E2E, khôi phục 5 tham số, tài khoản seed không đổi (audit giữ lại)', (int) q($pdo, 'SELECT COUNT(*) FROM users WHERE id > ? AND username LIKE ?', [$maxUser, '%' . $runId]) === 0
        && $paramsNow == $origParams && $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, username, role, status, password_hash) ORDER BY id) FROM users WHERE id <= {$maxUser}")->fetchColumn() === $seedUsers);
}

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
echo "\n{$pass}/{$total} PASS (HTTP test qua {$base}, run E2E{$runId})\n";
exit($pass === $total ? 0 : 1);
