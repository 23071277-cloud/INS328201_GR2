<?php

declare(strict_types=1);

/**
 * STEP 14 - Review bảo mật toàn hệ thống qua Apache XAMPP THẬT (APP_URL), DB energy_dss.
 *   php tests/core_security_test.php
 * 1) RBAC backend: MỌI route trong routes/web.php × 4 vai (BQL, KT, KTo, chưa đăng nhập) - so với config/permissions.php. Chỉ 3 vai trò (J-01 đã chốt).
 * 2) Fuzz: mọi POST với CSRF hợp lệ + input mảng / chuỗi rác + id không tồn tại; mọi GET với query mảng -> không 500,
 *    KHÔNG thay đổi dữ liệu nghiệp vụ (CHECKSUM TABLE trước/sau, trừ audit_logs, users).
 * 3) CSRF sai -> 403. 4) File nhạy cảm qua web. 5) Session: fixation, cookie flags, logout, timeout, khoá tài khoản.
 * 6) PII (SĐT) theo vai. 7) Trang lỗi chế độ production không lộ stack trace / SQL. 8) app.log không chứa mật khẩu / CSRF token.
 * Dữ liệu tạm (user khoá thử, cư dân có SĐT) XOÁ ở cuối.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/real_db_fixture.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$root = preg_replace('#/public$#', '', $base);
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$jar = static fn (string $who): string => sys_get_temp_dir() . "/sec_{$who}_{$runId}.txt";
$logFile = BASE_PATH . '/storage/logs/app.log';
clearstatcache();
$logBefore = filesize($logFile);

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function http(string $jar, string $method, string $url, $post = [], array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size), 'headers' => substr($raw, 0, $size)];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function login(string $base, string $jar, string $user, string $pw = 'Password@123'): array
{
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');

    return http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => $user, 'password' => $pw]);
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return $s->fetchColumn();
}

function dbChecksum(PDO $pdo): string
{
    $tables = array_diff($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), ['audit_logs', 'users']);
    $rows = $pdo->query('CHECKSUM TABLE ' . implode(', ', $tables))->fetchAll(PDO::FETCH_KEY_PAIR);

    return sha1(json_encode($rows));
}

function phpError(string $body): bool
{
    return (bool) preg_match('/Fatal error|Uncaught|Warning:|Notice:|Deprecated:|Stack trace|SQLSTATE|PDOException/i', $body);
}

$roles = ['bql' => 'bql01', 'ky_thuat' => 'kythuat01', 'ke_toan' => 'ketoan01'];
$tempUserId = 0;
$tempResidentId = 0;
$fx = null;
try {
    foreach ($roles as $role => $user) {
        check('LOGIN', "Đăng nhập {$user}", str_ends_with(login($base, $jar($role), $user)['location'], '/dashboard'));
    }
    $csrf = [];
    foreach ($roles as $role => $user) {
        $csrf[$role] = csrfFrom(http($jar($role), 'GET', $base . '/dashboard')['body']);
    }
    @unlink($jar('anon'));

    // ================================================================ 1) RBAC backend - mọi route
    preg_match_all("#\\\$router->(get|post)\\('([^']+)', \\[(\\w+)::class, '(\\w+)'\\], \\[([^\\]]*)\\]\\)#", (string) file_get_contents(BASE_PATH . '/routes/web.php'), $mm, PREG_SET_ORDER);
    $routes = [];
    foreach ($mm as $m) {
        $perm = preg_match("#'rbac:([\\w.\\-]+)'#", $m[5], $p) ? $p[1] : null;
        $routes[] = ['method' => strtoupper($m[1]), 'uri' => $m[2], 'perm' => $perm, 'auth' => str_contains($m[5], "'auth'"), 'csrf' => str_contains($m[5], "'csrf'")];
    }
    // Route CRUD FN-02 sinh bằng $masterDataRoutes($router, '<prefix>', '<permission>', …) - khai triển giống routes/web.php
    preg_match_all("#\\\$masterDataRoutes\\(\\\$router, '([\\w-]+)', '([\\w-]+)'#", (string) file_get_contents(BASE_PATH . '/routes/web.php'), $md, PREG_SET_ORDER);
    foreach ($md as [, $prefix, $perm]) {
        foreach ([['GET', '', 'view', false], ['GET', '/create', 'create', false], ['POST', '', 'create', true], ['GET', '/{id}', 'view', false],
            ['GET', '/{id}/edit', 'update', false], ['POST', '/{id}/update', 'update', true], ['POST', '/{id}/deactivate', 'deactivate', true]] as [$method, $suffix, $action, $hasCsrf]) {
            $routes[] = ['method' => $method, 'uri' => "/{$prefix}{$suffix}", 'perm' => "{$perm}.{$action}", 'auth' => true, 'csrf' => $hasCsrf];
        }
    }
    $declared = preg_match_all('#\$router->(get|post)\(#', (string) file_get_contents(BASE_PATH . '/routes/web.php'));
    check('RBAC', 'Phủ đủ route: ' . count($routes) . ' route (gồm ' . count($md) * 7 . ' route CRUD FN-02 sinh trong vòng lặp; còn thiếu chỉ GET /login)', count($md) === 4 && count($routes) === $declared - 7 + count($md) * 7 - 1);
    // Payment module: webhook ngân hàng giả lập là ngoại lệ DUY NHẤT có chủ đích - máy gọi máy nên không có phiên / CSRF; được bảo vệ bằng
    // HMAC-SHA256 + tắt mặc định (404) + giới hạn kích thước. Tách khỏi các bất biến auth / csrf / rbac bên dưới và kiểm tra riêng (mục WEBHOOK).
    $webhooks = ['/payments/callback'];
    $webhookRoutes = array_values(array_filter($routes, static fn ($r) => in_array($r['uri'], $webhooks, true)));
    $routes = array_values(array_filter($routes, static fn ($r) => !in_array($r['uri'], $webhooks, true)));
    // Mock Bank đã TÁCH thành ứng dụng độc lập (tools/mock-bank): Energy DSS không còn route /mock-bank -> phải 404 với MỌI vai trò + chưa đăng nhập.
    $postWithoutCsrf = array_filter($routes, static fn ($r) => $r['method'] === 'POST' && !$r['csrf']);
    check('RBAC', 'MỌI route POST đều có middleware csrf', $postWithoutCsrf === [], json_encode(array_column($postWithoutCsrf, 'uri')));
    $postWithoutAuth = array_filter($routes, static fn ($r) => $r['method'] === 'POST' && !$r['auth'] && $r['uri'] !== '/login');
    check('RBAC', 'MỌI route POST (trừ /login) đều có auth', $postWithoutAuth === [], json_encode(array_column($postWithoutAuth, 'uri')));
    $mutatingWithoutRbac = array_filter($routes, static fn ($r) => $r['method'] === 'POST' && $r['perm'] === null && !in_array($r['uri'], ['/login', '/logout', '/locale'], true));
    check('RBAC', 'MỌI route POST nghiệp vụ đều có rbac:<quyền>', $mutatingWithoutRbac === [], json_encode(array_column($mutatingWithoutRbac, 'uri')));

    $before = dbChecksum($pdo);
    $matrix = [];
    $wrong = [];
    $errors500 = [];
    foreach ($routes as $r) {
        if (in_array($r['uri'], ['/login', '/logout', '/locale'], true)) { // /locale: kiểm tra riêng (mục LOCALE)
            continue;
        }
        $uri = str_replace('{id}', '99999999', $r['uri']);
        foreach (array_merge(array_keys($roles), ['anon']) as $role) {
            if ($r['method'] === 'GET') {
                $res = http($jar($role), 'GET', $base . $uri . (str_contains($uri, '?') ? '&' : '?') . 'period_id[]=1&status[]=x&q[]=y&page[]=2');
            } else {
                $body = 'csrf_token=' . urlencode($csrf[$role] ?? '') . '&' . implode('&', array_map(static fn ($f) => urlencode($f . '[]') . '=x',
                    ['name', 'code', 'period_id', 'apartment_id', 'meter_id', 'current_reading', 'note', 'reason', 'status', 'version_token', 'scope', 'utility_type', 'threshold_value',
                     'target_value', 'reading_ids', 'charge_ids', 'decision', 'priority', 'content', 'month', 'year', 'unit_price', 'effective_date', 'tiers', 'reopen_reason', 'old_debt_amount']));
                $res = http($jar($role), 'POST', $base . $uri, $body);
            }
            $allowed = $role !== 'anon' && ($r['perm'] === null || in_array($role, config('permissions.' . $r['perm'], []), true));
            if ($r['perm'] !== null) {
                $matrix[$r['perm']][$role] = $allowed;
            }
            if ($res['code'] >= 500 || phpError($res['body'])) {
                $errors500[] = "{$role} {$r['method']} {$uri} = {$res['code']}";
            }
            if ($role === 'anon') {
                if (!($res['code'] === 302 && str_ends_with($res['location'], '/login'))) {
                    $wrong[] = "anon {$r['method']} {$uri} = {$res['code']}";
                }
            } elseif (!$allowed && $res['code'] !== 403) {
                $wrong[] = "{$role} {$r['method']} {$uri} = {$res['code']} (phải 403)";
            } elseif ($allowed && $res['code'] === 403 && !($role === 'ky_thuat' && str_starts_with($r['uri'], '/alerts/{id}'))) {
                $wrong[] = "{$role} {$r['method']} {$uri} = 403 (được phép)";
            }
        }
    }
    check('RBAC', 'Mọi route × 5 vai: trái quyền → 403, chưa đăng nhập → /login, đúng quyền không bị 403 (' . (count($routes) - 2) * 5 . ' request)', $wrong === [], implode(' | ', array_slice($wrong, 0, 8)));
    check('FUZZ', 'GET với query mảng + id không tồn tại, POST với CSRF hợp lệ + mọi field là mảng: không 500, không lỗi PHP', $errors500 === [], implode(' | ', array_slice($errors500, 0, 8)));
    check('FUZZ', 'Fuzz không làm thay đổi dữ liệu nghiệp vụ (CHECKSUM 25 bảng trước = sau)', dbChecksum($pdo) === $before);
    file_put_contents(sys_get_temp_dir() . '/core_permission_matrix.json', json_encode($matrix, JSON_PRETTY_PRINT));

    $scalarJunk = [];
    foreach ($routes as $r) {
        if ($r['method'] !== 'POST' || in_array($r['uri'], ['/login', '/logout'], true) || $r['perm'] === null) {
            continue;
        }
        $role = array_values(array_filter(array_keys($roles), static fn ($x) => in_array($x, config('permissions.' . $r['perm'], []), true)))[0] ?? null;
        if ($role === null) {
            continue;
        }
        $res = http($jar($role), 'POST', $base . str_replace('{id}', 'abc', $r['uri']), ['csrf_token' => $csrf[$role], 'period_id' => "1' OR '1'='1", 'apartment_id' => '-1', 'current_reading' => '1e309',
            'threshold_value' => 'NaN', 'target_value' => 'INF', 'scope' => 'zone:../../1', 'reading_ids' => 'x', 'version_token' => str_repeat('a', 5000)]);
        if ($res['code'] >= 500 || phpError($res['body'])) {
            $scalarJunk[] = "{$role} POST {$r['uri']} = {$res['code']}";
        }
        $res = http($jar($role), 'POST', $base . $r['uri'], ['csrf_token' => 'sai-token']);
        if ($res['code'] !== 403 && !str_contains($r['uri'], '{id}')) {
            $scalarJunk[] = "CSRF sai {$role} POST {$r['uri']} = {$res['code']}";
        }
    }
    check('FUZZ', 'POST với chuỗi rác (SQLi, -1, 1e309, NaN, INF, path traversal, token 5.000 ký tự), id "abc": không 500; CSRF sai → 403', $scalarJunk === [], implode(' | ', array_slice($scalarJunk, 0, 8)));
    check('FUZZ', 'Sau fuzz chuỗi rác: dữ liệu nghiệp vụ không đổi', dbChecksum($pdo) === $before);

    // ================================================================ 3a) Đổi ngôn ngữ vi / en: tuỳ chọn hiển thị trong session (như /logout: auth + csrf, không quyền nghiệp vụ, không ghi DB)
    $beforeLocale = dbChecksum($pdo);
    $roleL = array_key_first($roles);
    @unlink($jar('anonL'));
    $anonL = http($jar('anonL'), 'POST', $base . '/locale', ['locale' => 'en', 'back' => '/dashboard']);
    $badL = http($jar($roleL), 'POST', $base . '/locale', ['csrf_token' => 'sai', 'locale' => 'en', 'back' => '/dashboard']);
    $redir = [];
    foreach (['//evil.example/x', 'https://evil.example/', '/\\evil.example', "/x\r\nSet-Cookie: a=b", 'javascript:alert(1)', '/http://evil.example', '', '/payments?period_id=1'] as $back) {
        $res = http($jar($roleL), 'POST', $base . '/locale', ['csrf_token' => $csrf[$roleL], 'locale' => 'vi', 'back' => $back]);
        $redir[] = $res['code'] . ' ' . substr($res['location'], strlen($base));
    }
    $junkL = http($jar($roleL), 'POST', $base . '/locale', 'csrf_token=' . urlencode($csrf[$roleL]) . '&locale[]=en&back[]=x');
    http($jar($roleL), 'POST', $base . '/locale', ['csrf_token' => $csrf[$roleL], 'locale' => 'xx', 'back' => '/dashboard']);
    $stillVi = str_contains(http($jar($roleL), 'GET', $base . '/dashboard')['body'], '<html lang="vi">');
    check('LOCALE', 'POST /locale: chưa đăng nhập → /login; CSRF sai → 403; mảng rác không 500; ngôn ngữ lạ bị bỏ qua (vẫn vi); không ghi DB',
        $anonL['code'] === 302 && str_ends_with($anonL['location'], '/login') && $badL['code'] === 403 && $junkL['code'] === 302 && !phpError($junkL['body'])
        && $stillVi && dbChecksum($pdo) === $beforeLocale, json_encode([$anonL['code'], $badL['code'], $junkL['code'], $stillVi]));
    check('LOCALE', 'Chống open redirect: back ngoài ứng dụng (//host, http://, \\, xuống dòng, javascript:, rỗng) → /dashboard; đường dẫn nội bộ giữ nguyên (cả query)',
        $redir === array_merge(array_fill(0, 7, '302 /dashboard'), ['302 /payments?period_id=1']), implode(' | ', $redir));

    // ================================================================ 3b) Webhook thanh toán (ngoại lệ có chủ đích, kiểm tra riêng)
    check('WEBHOOK', 'Route công khai (không auth) duy nhất ngoài /login: POST /payments/callback — bảo vệ bằng HMAC, tắt mặc định', count($webhookRoutes) === 1 && $webhookRoutes[0]['method'] === 'POST' && !$webhookRoutes[0]['auth'] && $webhookRoutes[0]['perm'] === null);
    $beforeHook = dbChecksum($pdo);
    $hookBad = [];
    foreach ([['', []], ['{"transaction_id":"SEC1","amount":"1","content":"EDSS000001"}', []], ['{"transaction_id":"SEC2","amount":"1","content":"EDSS000001"}', ['X-Signature: ' . str_repeat('0', 64)]], [str_repeat('x', 9000), []]] as [$body, $hdr]) {
        $res = http($jar('anon2'), 'POST', $base . '/payments/callback', $body, array_merge(['Content-Type: application/json'], $hdr));
        if (!in_array($res['code'], [401, 404, 413, 503], true) || phpError($res['body'])) {
            $hookBad[] = 'body ' . strlen($body) . 'B' . ($hdr ? ' + chữ ký sai' : '') . " = {$res['code']}";
        }
    }
    check('WEBHOOK', 'Không chữ ký / chữ ký sai / payload quá lớn → 404 (tắt) / 401 / 413 / 503; không 500; không đổi dữ liệu', $hookBad === [] && dbChecksum($pdo) === $beforeHook, implode(' | ', $hookBad));
    $mockBad = [];
        foreach (array_merge(array_keys($roles), ['anon']) as $role) {
            foreach ([['GET', '/mock-bank', []], ['POST', '/mock-bank/send', ['csrf_token' => $csrf[$role] ?? '', 'transaction_id' => 'SEC-MB', 'amount' => '1', 'content' => 'EDSS000001']]] as [$m, $u, $body]) {
                $res = http($jar($role), $m, $base . $u, $body);
                if ($res['code'] !== 404 || phpError($res['body'])) {
                    $mockBad[] = "{$role} {$m} {$u} = {$res['code']}";
                }
            }
        }
        check('MOCKBANK', 'Mock Bank không thuộc Energy DSS (ứng dụng độc lập): /mock-bank + /mock-bank/send → 404 với mọi vai trò và người chưa đăng nhập', $mockBad === [], implode(' | ', $mockBad));

    // ================================================================ 4) File nhạy cảm qua web
    $leaks = [];
    foreach (['/.env', '/storage/logs/app.log', '/database/energy_dss_v2.sql', '/database/',
        '/app/Core/Database.php', '/config/database.php', '/bootstrap.php', '/README.md', '/tests/core_security_test.php',
        '/storage/imports/', '/routes/web.php', '/docs/DATABASE.md', '/lib/PHPMailer/src/SMTP.php', '/storage/logs/mail.log', '/config/mail.php', '/.gitignore'] as $p) {
        foreach ([$root, $base, $base . '/..'] as $prefix) {
            $res = http($jar('anon2'), 'GET', $prefix . $p);
            if ($res['code'] === 200 && preg_match('/DB_PASSWORD|CREATE TABLE|<\?php|password_hash|\[SECURITY\]|# Energy|namespace App/', $res['body'])) {
                $leaks[] = $prefix . $p;
            }
        }
    }
    check('FILES', 'Không tải được .env, log, SQL/backup, mã nguồn, docs, test, storage qua web (3 tiền tố URL)', $leaks === [], implode(', ', $leaks));
    $dir = http($jar('anon2'), 'GET', $base . '/assets/');
    // STEP 15: đã sửa LOW của STEP 14 bằng "Options -Indexes" (public/.htaccess + .htaccess gốc).
    check('FILES', '/assets/ KHÔNG liệt kê thư mục (Options -Indexes, STEP 15)', !str_contains($dir['body'], 'Index of')
        && (str_contains($base, '/energy-dss/public') ? $dir['code'] === 403 : in_array($dir['code'], [403, 404], true) && !preg_match('#href="(css|js|img)/"#', $dir['body'])), (string) $dir['code']); // Apache 403; php -S (DB v2): 404 của app (có <link> app.css), không liệt kê

    // ================================================================ 5) Session
    @unlink($jar('fix'));
    file_put_contents($jar('fix'), "localhost\tFALSE\t/\tFALSE\t0\tPHPSESSID\tattackerfixedsessionid123\n");
    $page = http($jar('fix'), 'GET', $base . '/login');
    $r = http($jar('fix'), 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => 'bql01', 'password' => 'Password@123']);
    preg_match('/PHPSESSID=([^;]+)/', $r['headers'], $sid);
    check('SESSION', 'Chống session fixation: sau đăng nhập session ID mới (≠ ID kẻ tấn công cài)', isset($sid[1]) && $sid[1] !== 'attackerfixedsessionid123');
    $cookie = http($jar('new'), 'GET', $base . '/login')['headers'];
    check('SESSION', 'Cookie phiên HttpOnly + SameSite=Lax', (bool) preg_match('/Set-Cookie: PHPSESSID=[^\r\n]*HttpOnly/i', $cookie) && (bool) preg_match('/Set-Cookie: PHPSESSID=[^\r\n]*SameSite=Lax/i', $cookie));
    $lo = http($jar('fix'), 'POST', $base . '/logout', ['csrf_token' => 'sai']);
    check('SESSION', 'Đăng xuất với CSRF sai → 403, phiên vẫn còn', $lo['code'] === 403 && http($jar('fix'), 'GET', $base . '/dashboard')['code'] === 200);
    $tok = csrfFrom(http($jar('fix'), 'GET', $base . '/dashboard')['body']);
    $lo = http($jar('fix'), 'POST', $base . '/logout', ['csrf_token' => $tok]);
    check('SESSION', 'Đăng xuất → /login; /dashboard sau đó → /login', str_ends_with($lo['location'], '/login') && str_ends_with(http($jar('fix'), 'GET', $base . '/dashboard')['location'], '/login'));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . BASE_PATH . '/bootstrap.php"; session_save_path(sys_get_temp_dir()); App\Core\Session::start(); $_SESSION["user_id"]=1; $_SESSION["last_activity"]=time()-31*60; $_SESSION["csrf_token"]="x"; '
        . '(new ReflectionMethod(App\Core\Session::class, "enforceTimeout"))->invoke(null); echo isset($_SESSION["user_id"]) ? "CON" : "HET";') . ' 2>/dev/null');
    check('SESSION', 'Hết hạn phiên sau 30 phút không hoạt động (NFR-01): user_id bị xóa', trim((string) $out) === 'HET', (string) $out);

    // khoá tài khoản sau 5 lần sai (user tạm)
    $hash = (string) q($pdo, 'SELECT password_hash FROM users WHERE id = 1');
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, status) VALUES (?, ?, 'Tạm review', 'ke_toan', 'hoat_dong')")->execute(["sec{$runId}", $hash]);
    $tempUserId = (int) $pdo->lastInsertId();
    for ($i = 0; $i < 5; $i++) {
        login($base, $jar('lock'), "sec{$runId}", 'sai-mat-khau');
    }
    $r = login($base, $jar('lock'), "sec{$runId}", 'Password@123');
    check('AUTH', 'Sai 5 lần → khóa tạm (BR-02): mật khẩu đúng vẫn bị từ chối', q($pdo, 'SELECT status FROM users WHERE id = ?', [$tempUserId]) === 'khoa_tam' && !str_ends_with($r['location'], '/dashboard'));
    $r = login($base, $jar('lock'), 'khong_ton_tai_' . $runId, 'x');
    $msg = http($jar('lock'), 'GET', $base . '/login')['body'];
    check('AUTH', 'Sai username: thông báo chung, không lộ tài khoản có tồn tại (VAL-01.02)', str_contains($msg, 'Tên đăng nhập hoặc mật khẩu không đúng.'));
    check('AUTH', 'Mật khẩu lưu bcrypt ($2y$), không có cột mật khẩu rõ', str_starts_with($hash, '$2y$') && (int) q($pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name LIKE '%password%' AND column_name <> 'password_hash'") === 0);

    // ================================================================ 6) PII (SĐT)
    // dữ liệu PII trên căn / bản tính / kỳ FIXTURE riêng (đã khoá) - không thêm cư dân vào căn, không đọc kỳ / phiếu của người dùng
    $fx = e2e_fixture_create($pdo, $runId);
    $lp = e2e_create_locked_period($pdo, $fx, 2089, $runId);
    $fxApt = $fx['apt']['BT1'];
    $fxCharge = (int) q($pdo, 'SELECT id FROM apartment_charges WHERE billing_period_id = ? AND apartment_id = ?', [$lp['id'], $fxApt]);
    $scope = "period_id={$lp['id']}&building_id={$fx['building']}";
    $pdo->prepare("INSERT INTO residents (apartment_id, full_name, phone, resident_role, is_active, effective_from) VALUES (?, 'Review PII', '0912345678', 'chu_ho', 1, '2024-01-01')")->execute([$fxApt]);
    $tempResidentId = (int) $pdo->lastInsertId();
    $pages = ["/apartments/{$fxApt}", "/charges/{$fxCharge}/notice", "/reports/utility?{$scope}", "/dashboard?{$scope}", '/alerts', "/reports/utility/export?{$scope}"];
    $piiLeak = [];
    foreach (['ky_thuat', 'ke_toan'] as $role) {
        foreach ($pages as $p) {
            if (str_contains(http($jar($role), 'GET', $base . $p)['body'], '0912345678')) {
                $piiLeak[] = "{$role} {$p}";
            }
        }
    }
    check('PII', 'SĐT đầy đủ không xuất hiện trong HTML/CSV với Kỹ thuật, Kế toán (BR-36, D10)', $piiLeak === [], implode(', ', $piiLeak));
    $bqlApt = http($jar('bql'), 'GET', $base . "/apartments/{$fxApt}")['body'];
    $ktApt = http($jar('ky_thuat'), 'GET', $base . "/apartments/{$fxApt}")['body'];
    check('PII', 'BQL thấy SĐT đầy đủ; Kỹ thuật thấy dạng che (••••••5678)', str_contains($bqlApt, '0912345678') && str_contains($ktApt, '••••••5678'));
    check('PII', 'Phiếu / báo cáo / dashboard / cảnh báo không hiển thị SĐT kể cả với BQL', $fxCharge > 0 && !str_contains(http($jar('bql'), 'GET', $base . "/charges/{$fxCharge}/notice")['body'], '0912345678')
        && !str_contains(http($jar('bql'), 'GET', $base . "/reports/utility/export?{$scope}")['body'], '0912345678'));

    // ================================================================ 7) Trang lỗi
    $nf = http($jar('bql'), 'GET', $base . '/khong-co-route-nay');
    check('ERROR', '404 route lạ: trang 404, không lộ thông tin', $nf['code'] === 404 && !phpError($nf['body']));
    $prod = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . BASE_PATH . '/bootstrap.php"; App\Core\Config::set("app.debug", false); '
        . 'throw new PDOException("SQLSTATE[42S02]: SELECT password_hash FROM users secret_db_pw");') . ' 2>/dev/null');
    check('ERROR', 'Chế độ production (APP_DEBUG=false): lỗi bất ngờ / lỗi DB → trang 500 chung, không lộ SQL, stack trace, đường dẫn', is_string($prod) && str_contains($prod, '500')
        && !preg_match('/SQLSTATE|password_hash|secret_db_pw|Stack trace|\.php/', $prod), (string) $prod);
    $dbg = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . BASE_PATH . '/bootstrap.php"; App\Core\Config::set("app.debug", true); throw new RuntimeException("dbg-marker");') . ' 2>/dev/null');
    check('ERROR', 'Chế độ debug (môi trường local): chi tiết lỗi chỉ hiện khi APP_DEBUG=true (đã escape)', str_contains((string) $dbg, 'dbg-marker'));

    // ================================================================ 8) Log
    clearstatcache();
    $newLog = (string) file_get_contents($logFile, false, null, $logBefore);
    $allLog = (string) file_get_contents($logFile);
    check('LOG', 'app.log ghi được qua Apache trong lần chạy này ([SECURITY] CSRF bị từ chối)', str_contains($newLog, '[SECURITY] CSRF token rejected'));
    $tokens = array_values(array_filter($csrf));
    check('LOG', 'app.log không chứa mật khẩu demo / CSRF token / mật khẩu sai đã thử', !str_contains($allLog, 'Password@123') && !str_contains($allLog, 'sai-mat-khau')
        && array_filter($tokens, static fn ($t) => str_contains($allLog, $t)) === []);
    $appErrors = array_filter(explode(PHP_EOL, $newLog), static fn ($l) => preg_match('/PHP Error|Fatal Error|Uncaught/i', $l) && !str_contains($l, 'Command line code'));
    check('LOG', 'Lần chạy này không sinh lỗi PHP từ request web (bỏ qua 2 lỗi do test cố ý giả lập trong CLI)', $appErrors === [], implode(' | ', array_slice($appErrors, 0, 3)));
} finally {
    if ($tempUserId > 0) {
        $pdo->prepare('UPDATE audit_logs SET user_id = NULL WHERE user_id = ?')->execute([$tempUserId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$tempUserId]);
    }
    if ($tempResidentId > 0) {
        $pdo->prepare('DELETE FROM residents WHERE id = ?')->execute([$tempResidentId]);
    }
    $left = 0;
    if ($fx !== null) {
        $_SESSION = [];
        e2e_cleanup($pdo, $fx); // kỳ test + tòa / căn / công tơ / cư dân fixture
        $left = e2e_leftovers($pdo, $fx);
    }
    check('CLEANUP', 'Đã xóa user / cư dân / fixture tạm của lượt chạy', $left === 0 && (int) q($pdo, 'SELECT COUNT(*) FROM users WHERE username = ?', ["sec{$runId}"]) === 0
        && (int) q($pdo, 'SELECT COUNT(*) FROM residents WHERE id = ?', [$tempResidentId]) === 0);
    foreach (array_merge(array_keys($roles), ['anon', 'anon2', 'fix', 'new', 'lock']) as $who) {
        @unlink($jar($who));
    }
}

$failed = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-8s %s%s\n", $ok ? 'PASS' : 'FAIL', $g, $n, $ok || $d === '' ? '' : "  -> {$d}");
}
printf("\n%d/%d PASS (security review qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
