<?php

declare(strict_types=1);

/**
 * UI trang đăng nhập (giao diện mới) - nghiệp vụ đăng nhập KHÔNG đổi. DB TẠM + php -S (tests/run_login_ui_tests.sh).
 *   php tests/login_ui_test.php <base_url> <db_tạm> [thư_mục_ảnh]
 * HTTP: markup, ảnh nền, đăng nhập đúng / sai (form thường), CSRF sai, thông báo chung, khoá tạm sau 5 lần sai vẫn hoạt động.
 * Chrome headless (trang điều khiển cùng origin): hiện / ẩn mật khẩu, lỗi trong thẻ KHÔNG tải lại trang, trạng thái đang xử lý,
 * không lộ lỗi kỹ thuật, đăng nhập đúng -> /dashboard. Chụp ảnh trạng thái lỗi nếu truyền thư mục ảnh.
 */

[$_, $base, $testDb, $shotDir] = $argv + [null, '', '', ''];
if ($base === '' || $testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Dùng: php tests/login_ui_test.php <base_url> <db_tạm> [thư_mục_ảnh]\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_ENV['DB_DATABASE'] = $testDb;
App\Core\Config::set('database.database', $testDb);
$pdo = App\Core\Database::connection();
$base = rtrim($base, '/');
$jar = static fn (string $who): string => sys_get_temp_dir() . "/lui_{$who}_" . getmypid() . '.txt';

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function http(string $jar, string $method, string $url, array $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'type' => $type, 'body' => substr($raw, $size)];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function findChrome(): ?string
{
    foreach ([(string) getenv('CHROME_BIN'), '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'] as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) {
            return $c;
        }
    }

    return null;
}

try {
    // ================================================================ Markup + tài nguyên
    $G = 'MARKUP';
    @unlink($jar('a'));
    $page = http($jar('a'), 'GET', $base . '/login');
    $html = $page['body'];
    check($G, 'GET /login 200: tiêu đề, phụ đề, 2 ô có nhãn + dấu * đỏ, nút Đăng nhập, ô demo', $page['code'] === 200 && str_contains($html, 'Energy &amp; Utility DSS') && str_contains($html, 'Đăng nhập hệ thống')
        && str_contains($html, '<label for="username">Tên đăng nhập <span class="required">*</span>') && str_contains($html, '<label for="password">Mật khẩu <span class="required">*</span>')
        && str_contains($html, 'data-login-submit') && str_contains($html, 'Tài khoản demo (database/energy_dss_v2.sql):') && str_contains($html, '<code>Password@123</code>'));
    check($G, 'giữ CSRF + autocomplete; nút hiện mật khẩu ẩn khi không có JS (hidden) + aria', str_contains($html, 'name="csrf_token"') && str_contains($html, 'autocomplete="current-password"')
        && (bool) preg_match('/data-toggle-password aria-controls="password" aria-pressed="false" aria-label="Hiện mật khẩu" hidden>/', $html));
    check($G, 'không tải script / font / ảnh từ bên ngoài (chạy offline)', !preg_match('#(src|href)="https?://(?!127\.0\.0\.1|localhost)#', $html));
    foreach (['login-bg.jpg', 'login-bg-mobile.jpg'] as $img) {
        $r = http($jar('a'), 'GET', $base . '/assets/img/' . $img);
        check($G, "ảnh nền {$img}: 200, JPEG, < 700 KB", $r['code'] === 200 && str_starts_with($r['body'], "\xFF\xD8") && strlen($r['body']) < 700 * 1024, $r['code'] . ' / ' . strlen($r['body']));
    }

    // ================================================================ Nghiệp vụ đăng nhập không đổi (form thường, không JS)
    $G = 'AUTH';
    $r = http($jar('a'), 'POST', $base . '/login', ['csrf_token' => csrfFrom($html), 'username' => 'bql01', 'password' => 'sai']);
    $back = http($jar('a'), 'GET', $base . '/login')['body'];
    check($G, 'Sai mật khẩu (không JS) → 302 /login, lỗi hiện trong thẻ lỗi, giữ tên đăng nhập, không lộ lỗi kỹ thuật', $r['code'] === 302 && str_ends_with($r['location'], '/login')
        && (bool) preg_match('/data-login-alert role="alert" aria-live="assertive">.*Tên đăng nhập hoặc mật khẩu không đúng\./s', $back) && str_contains($back, 'value="bql01"')
        && !preg_match('/exception|PDO|SQLSTATE|stack trace/i', $back));
    $r = http($jar('a'), 'POST', $base . '/login', ['csrf_token' => 'sai', 'username' => 'bql01', 'password' => 'Password@123']);
    check($G, 'CSRF sai → 403 (giữ nguyên)', $r['code'] === 403);
    $r = http($jar('a'), 'POST', $base . '/login', ['csrf_token' => csrfFrom($back), 'username' => 'bql01', 'password' => 'Password@123'], );
    check($G, 'Đăng nhập đúng (không JS) → 302 /dashboard', $r['code'] === 302 && str_ends_with($r['location'], '/dashboard'));
    @unlink($jar('b'));
    $p = http($jar('b'), 'GET', $base . '/login');
    $live = (function () use ($jar, $base, $p) {
        $ch = curl_init($base . '/login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar('b'), CURLOPT_COOKIEFILE => $jar('b'), CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['X-Live-Request: 1', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => csrfFrom($p['body']), 'username' => 'kythuat01', 'password' => 'sai'])]);
        $b = (string) curl_exec($ch);
        curl_close($ch);

        return json_decode($b, true);
    })();
    check($G, 'Gửi bằng fetch (X-Live-Request) sai mật khẩu → JSON {redirect: /login} (cùng xử lý server)', ($live['redirect'] ?? '') === $base . '/login', json_encode($live));
    // khoá tạm sau 5 lần sai (BR-02) vẫn hoạt động với giao diện mới
    $pdo->exec("UPDATE users SET failed_login_count = 0, locked_until = NULL, status = 'hoat_dong' WHERE username = 'ketoan01'");
    @unlink($jar('c'));
    for ($i = 0; $i < 5; $i++) {
        $lp = http($jar('c'), 'GET', $base . '/login');
        http($jar('c'), 'POST', $base . '/login', ['csrf_token' => csrfFrom($lp['body']), 'username' => 'ketoan01', 'password' => 'sai' . $i]);
    }
    $lp = http($jar('c'), 'GET', $base . '/login');
    $r = http($jar('c'), 'POST', $base . '/login', ['csrf_token' => csrfFrom($lp['body']), 'username' => 'ketoan01', 'password' => 'Password@123']);
    $msg = http($jar('c'), 'GET', $base . '/login')['body'];
    check($G, 'Sai 5 lần → khoá tạm (BR-02): mật khẩu đúng vẫn bị từ chối, thông báo hiện trong thẻ lỗi', $pdo->query("SELECT status FROM users WHERE username = 'ketoan01'")->fetchColumn() === 'khoa_tam'
        && !str_ends_with($r['location'], '/dashboard') && (bool) preg_match('/data-login-alert role="alert" aria-live="assertive">\s*<svg/s', $msg) && !str_contains($msg, 'data-login-alert role="alert" aria-live="assertive" hidden'));

    // ================================================================ Trình duyệt
    $G = 'BROWSER';
    $chrome = findChrome();
    if ($chrome === null) {
        check($G, 'SKIPPED: không tìm thấy Chrome', true);
    } else {
        $out = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --window-size=1440,900 --virtual-time-budget=30000 --dump-dom ' . escapeshellarg($base . '/__login_harness.html?mode=full') . ' 2>/dev/null');
        preg_match('#<pre id="__result"[^>]*>(.*?)</pre>#s', $out, $m);
        $r = json_decode(html_entity_decode($m[1] ?? ''), true);
        if (!is_array($r) || isset($r['error'])) {
            check($G, 'chạy được kịch bản đăng nhập trong Chrome', false, json_encode($r, JSON_UNESCAPED_UNICODE));
        } else {
            $j = static fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
            check($G, 'Hiện / ẩn mật khẩu: nút hiện khi có JS, đổi type text ↔ password + aria-pressed / aria-label', $r['toggle']['visible'] === true && $r['toggle']['before'] === 'password'
                && $r['toggle']['shown'] === ['text', 'true', 'Ẩn mật khẩu'] && $r['toggle']['hiddenAgain'] === ['password', 'false'], $j($r['toggle']));
            check($G, 'Bố cục: thẻ kính mờ (backdrop blur) rộng 460–520px, ảnh nền khu đô thị', $r['layout']['blur'] && $r['layout']['bg'] && $r['layout']['cardW'] >= 460 && $r['layout']['cardW'] <= 520, $j($r['layout']));
            check($G, 'Bỏ trống → báo "Vui lòng nhập tên đăng nhập và mật khẩu." ngay trong thẻ', $r['empty'] === [true, 'Vui lòng nhập tên đăng nhập và mật khẩu.'], $j($r['empty']));
            check($G, 'Đang gửi → nút ở trạng thái đang xử lý (spinner, khoá nút)', $r['loadingDuring'] === true);
            $f = $r['fail'];
            check($G, 'Đăng nhập SAI → lỗi trong thẻ, KHÔNG tải lại trang, xoá ô mật khẩu, giữ tên, nút trở lại "Đăng nhập", không lộ lỗi kỹ thuật',
                $f['noReload'] && $f['visible'] && $f['text'] === 'Tên đăng nhập hoặc mật khẩu không đúng.' && $f['passwordCleared'] && $f['username'] === 'bql01' && $f['button'] === 'Đăng nhập'
                && $f['technical'] === false && $f['path'] === '/login', $j($f));
            check($G, 'Đăng nhập ĐÚNG → vào /dashboard (giao diện ứng dụng)', ($r['success']['path'] ?? '') === '/dashboard' && ($r['success']['hasSidebar'] ?? false), $j($r['success'] ?? null));
        }
        if ($shotDir !== '') {
            shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --hide-scrollbars --window-size=1440,900 --virtual-time-budget=20000 --screenshot=' . escapeshellarg(rtrim($shotDir, '/') . '/login_error.png')
                . ' ' . escapeshellarg($base . '/__login_harness.html?mode=shot') . ' 2>/dev/null');
            check($G, 'Đã chụp ảnh trạng thái lỗi', is_file(rtrim($shotDir, '/') . '/login_error.png'));
        }
    }
} finally {
    foreach (['a', 'b', 'c'] as $who) {
        @unlink($jar($who));
    }
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
echo "\n{$pass}/{$total} PASS (login UI qua {$base}, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
