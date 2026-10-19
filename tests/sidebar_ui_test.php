<?php

declare(strict_types=1);

/**
 * UI sidebar dạng nhóm thu gọn / mở rộng cho cả 3 vai trò (mỗi vai trò giữ cấu trúc menu riêng). DB TẠM + php -S.
 *   php tests/sidebar_ui_test.php <base_url> <db_tạm>      (gọi qua tests/run_sidebar_ui_tests.sh)
 * 1) HTML server: đúng thứ tự nhóm / mục / URL; mỗi trang (kể cả trang con) chỉ mở sẵn nhóm chứa trang đó, mục đang mở được highlight;
 *    badge mục con còn nguyên, nhóm đang đóng có tổng badge; Kế toán / Kỹ thuật cùng kiểu, không lộ mục ẩn.
 * 2) Chrome headless (/__sidebar_harness.html): bấm mở / đóng thật, có animation, chevron xoay, mở nhiều nhóm cùng lúc, mục trong nhóm đóng
 *    không Tab tới được, layout không nhảy, tải lại về mặc định, trang con mở sẵn nhóm cha mà không chạy animation.
 */

[$_, $base, $testDb] = $argv + [null, '', ''];
if ($base === '' || $testDb === '' || in_array($testDb, ['energy_dss', 'energy_dss_v2'], true)) {
    fwrite(STDERR, "Dùng: php tests/sidebar_ui_test.php <base_url> <db_tạm>\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_ENV['DB_DATABASE'] = $testDb;
App\Core\Config::set('database.database', $testDb);
$pdo = App\Core\Database::connection();
$base = rtrim($base, '/');
$jar = static fn (string $who): string => sys_get_temp_dir() . "/sbui_{$who}_" . getmypid() . '.txt';

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function http(string $jar, string $method, string $url, array $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $body];
}

function login(string $base, string $jar, string $user): bool
{
    @unlink($jar);
    preg_match('/name="csrf_token" value="([^"]+)"/', http($jar, 'GET', $base . '/login')['body'], $m);

    return http($jar, 'POST', $base . '/login', ['csrf_token' => $m[1] ?? '', 'username' => $user, 'password' => 'Password@123'])['code'] === 302;
}

/** Sidebar BQL -> [['label','open','aria','controls','sum'(null = ẩn),'items' => [['label','href','active','badge']]]] */
function navGroups(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $x = new DOMXPath($dom);
    $out = [];
    foreach ($x->query('//aside[@id="appSidebar"]//div[@data-nav-group]') as $g) {
        $btn = $x->query('.//button[@data-nav-group-toggle]', $g)->item(0);
        $panel = $x->query('.//div[contains(@class,"nav-group__panel")]', $g)->item(0);
        $sum = $x->query('.//span[@data-nav-group-sum]', $g)->item(0);
        $items = [];
        foreach ($x->query('.//div[contains(@class,"nav-group__inner")]/a', $g) as $a) {
            $label = trim((string) preg_replace('/\s+/u', ' ', $x->query('./text()', $a)->item(1)?->textContent ?? ''));
            $badge = $x->query('.//span[@data-issue-count and not(@hidden)]/span[@aria-hidden] | .//span[@data-payment-review-count]', $a)->item(0);
            $items[] = ['label' => $label, 'href' => $a->getAttribute('href'), 'active' => str_contains($a->getAttribute('class'), 'is-active'),
                'badge' => $badge === null ? null : (int) $badge->textContent];
        }
        $out[] = ['label' => trim($x->query('.//span[contains(@class,"nav-group__label")]', $g)->item(0)->textContent),
            'open' => str_contains($g->getAttribute('class'), 'is-open'), 'aria' => $btn->getAttribute('aria-expanded'),
            'controls' => $btn->getAttribute('aria-controls') !== '' && $btn->getAttribute('aria-controls') === $panel->getAttribute('id'),
            'sum' => $sum->hasAttribute('hidden') ? null : (int) $sum->textContent, 'items' => $items];
    }

    return $out;
}

function findChrome(): ?string
{
    foreach ([(string) getenv('CHROME_BIN'), '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) {
            return $c;
        }
    }

    return null;
}

function harness(string $chrome, string $url): ?array
{
    // --virtual-time-budget: đợi kịch bản (timer + transition CSS, tự đợi transitionend) chạy xong rồi mới dump DOM.
    $out = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --window-size=1400,1000 --virtual-time-budget=60000 --dump-dom '
        . escapeshellarg($url) . ' 2>/dev/null');
    preg_match('#<pre id="__result">(.*?)</pre>#s', $out, $m);
    $r = json_decode(html_entity_decode($m[1] ?? ''), true);

    return is_array($r) ? $r : null;
}

try {
    // ================================================================ 1. Dữ liệu: 1 giao dịch cần kiểm tra + 1 email lỗi -> badge ở 2 nhóm
    $pdo->exec("INSERT INTO payment_transactions (transaction_id, source, amount, transfer_content, payer_name, received_at, apartment_charge_id, match_status, raw_payload)
        VALUES ('SBUI-1', 'mock_bank', 1000, 'EDSS999999', 'Test', NOW(), NULL, 'unknown_ref', '{}')");
    $aptId = (int) $pdo->query('SELECT MIN(id) FROM apartments')->fetchColumn();
    $pdo->exec("INSERT INTO email_notifications (notification_type, apartment_id, recipient_email, subject, body_html, body_text, content_hash, transport, status, attempt_count, last_error)
        VALUES ('bill_notice', {$aptId}, 'sbui@example.invalid', 'x', 'x', 'x', SHA2('sbui', 256), 'log', 'failed', 1, 'test')");
    $periodId = (int) $pdo->query('SELECT MAX(id) FROM billing_periods')->fetchColumn();
    $buildingId = (int) $pdo->query('SELECT MIN(id) FROM buildings')->fetchColumn();
    $meterId = (int) $pdo->query('SELECT MIN(id) FROM meters')->fetchColumn();

    // ================================================================ 2. HTML server - BQL
    $G = 'BQL HTML';
    check($G, 'Đăng nhập bql01', login($base, $jar('bql'), 'bql01'));
    $dash = http($jar('bql'), 'GET', $base . '/dashboard')['body'];
    $groups = navGroups($dash);
    $structure = array_map(static fn ($g) => [$g['label'], array_map(static fn ($i) => $i['label'] . ' ' . substr($i['href'], strlen($base)), $g['items'])], $groups);
    $expected = [
        ['Quản trị hệ thống', ['Quản lý tài khoản /users', 'Nhật ký audit /audit-logs', 'Tham số hệ thống /system-parameters']],
        ['Danh mục', ['Tòa nhà /buildings', 'Khu vực /zones', 'Căn hộ /apartments', 'Công tơ /meters']],
        ['Biểu giá & phí', ['Biểu giá điện/nước /tariffs', 'Phí cố định & gửi xe /fees', 'Đề xuất biểu giá /tariff-proposals']],
        ['Vận hành & chỉ số', ['Kỳ ghi chỉ số /periods', 'Tra cứu chỉ số /readings', 'Tính phí theo kỳ /periods']],
        ['Theo dõi & phân tích', ['Mức nền (Baseline) /baselines', 'So sánh tiêu thụ /comparisons', 'Mục tiêu & theo dõi /targets', 'Cảnh báo /alerts', 'Quy tắc ngưỡng /alert-rules', 'Quy tắc khuyến nghị /recommendation-rules']],
        ['Thanh toán', ['Theo dõi thanh toán /payments', 'Cần kiểm tra /payments/review']],
        ['Thông báo', ['Hộp thư đi /email-notifications']],
        ['Báo cáo', ['Báo cáo tiện ích /reports/utility']],
    ];
    check($G, 'Dashboard đứng đầu, 8 nhóm đúng thứ tự, đủ 23 mục cũ, đúng URL (không mã FN-xx)', $structure === $expected
        && str_contains($dash, '📊</span> Dashboard') && !preg_match('/\(FN-\d/', (string) preg_replace('#.*<aside[^>]*>(.*?)</aside>.*#s', '$1', $dash)), json_encode($structure, JSON_UNESCAPED_UNICODE));
    check($G, 'Mỗi nhóm là nút bấm (button aria-expanded + aria-controls trỏ đúng panel) có chevron', count(array_filter($groups, static fn ($g) => $g['controls'])) === 8
        && substr_count($dash, 'class="nav-group__chevron"') === 8 && substr_count($dash, '<button type="button" class="nav-group__toggle"') === 8);
    $cssV = filemtime(BASE_PATH . '/public/assets/css/app.css');
    $jsV = filemtime(BASE_PATH . '/public/assets/js/app.js');
    check($G, 'Chống cache cũ: CSS / JS có ?v=<thời điểm sửa file> (HTML mới không đi với app.css cũ -> header nhóm thành nút trắng, chevron khổng lồ); chevron có kích thước sẵn 14×14',
        str_contains($dash, '/assets/css/app.css?v=' . $cssV . '"') && str_contains($dash, '/assets/js/app.js?v=' . $jsV . '"')
        && substr_count($dash, '<svg class="nav-group__chevron" width="14" height="14"') === 8);
    check($G, '/dashboard: không thuộc nhóm nào -> mọi nhóm đóng (aria-expanded=false)', array_filter($groups, static fn ($g) => $g['open'] || $g['aria'] !== 'false') === []);
    $byLabel = array_column($groups, null, 'label');
    check($G, 'Badge mục con còn nguyên (Cần kiểm tra = 1, Hộp thư đi = 1); nhóm đóng hiện tổng badge (Thanh toán 1, Thông báo 1, nhóm khác ẩn)',
        $byLabel['Thanh toán']['items'][1]['badge'] === 1 && $byLabel['Thông báo']['items'][0]['badge'] === 1
        && $byLabel['Thanh toán']['sum'] === 1 && $byLabel['Thông báo']['sum'] === 1
        && array_filter($groups, static fn ($g) => !in_array($g['label'], ['Thanh toán', 'Thông báo'], true) && $g['sum'] !== null) === [],
        json_encode(array_map(static fn ($g) => [$g['label'], $g['sum']], $groups), JSON_UNESCAPED_UNICODE));

    // Trang -> [nhóm mở sẵn duy nhất, mục highlight]
    $pages = [
        '/users' => ['Quản trị hệ thống', ['Quản lý tài khoản']],
        '/audit-logs' => ['Quản trị hệ thống', ['Nhật ký audit']],
        '/system-parameters' => ['Quản trị hệ thống', ['Tham số hệ thống']],
        '/buildings' => ['Danh mục', ['Tòa nhà']],
        "/buildings/{$buildingId}" => ['Danh mục', ['Tòa nhà']],
        '/zones' => ['Danh mục', ['Khu vực']],
        '/apartments' => ['Danh mục', ['Căn hộ']],
        "/meters/{$meterId}" => ['Danh mục', ['Công tơ']],
        '/tariffs' => ['Biểu giá & phí', ['Biểu giá điện/nước']],
        '/fees' => ['Biểu giá & phí', ['Phí cố định & gửi xe']],
        '/tariff-proposals' => ['Biểu giá & phí', ['Đề xuất biểu giá']],
        '/periods' => ['Vận hành & chỉ số', ['Kỳ ghi chỉ số']],
        "/periods/{$periodId}" => ['Vận hành & chỉ số', ['Kỳ ghi chỉ số']],
        "/periods/{$periodId}/billing" => ['Vận hành & chỉ số', ['Kỳ ghi chỉ số', 'Tính phí theo kỳ']],
        '/readings' => ['Vận hành & chỉ số', ['Tra cứu chỉ số']],
        '/baselines' => ['Theo dõi & phân tích', ['Mức nền (Baseline)']],
        '/comparisons' => ['Theo dõi & phân tích', ['So sánh tiêu thụ']],
        '/targets' => ['Theo dõi & phân tích', ['Mục tiêu & theo dõi']],
        '/alerts' => ['Theo dõi & phân tích', ['Cảnh báo']],
        '/alert-rules' => ['Theo dõi & phân tích', ['Quy tắc ngưỡng']],
        '/recommendation-rules/create' => ['Theo dõi & phân tích', ['Quy tắc khuyến nghị']],
        '/payments' => ['Thanh toán', ['Theo dõi thanh toán']],
        '/payments/review' => ['Thanh toán', ['Cần kiểm tra']],
        '/email-notifications' => ['Thông báo', ['Hộp thư đi']],
        '/reports/utility' => ['Báo cáo', ['Báo cáo tiện ích']],
    ];
    $pageCheck = static function (string $who, array $pages) use ($jar, $base): array {
        $bad = [];
        foreach ($pages as $path => [$wantGroup, $wantActive]) {
            $r = http($jar($who), 'GET', $base . $path);
            $gs = navGroups($r['body']);
            $open = array_values(array_map(static fn ($g) => $g['label'], array_filter($gs, static fn ($g) => $g['open'] && $g['aria'] === 'true')));
            $active = array_merge(...array_map(static fn ($g) => array_map(static fn ($i) => $i['label'], array_filter($g['items'], static fn ($i) => $i['active'])), $gs));
            $closedAria = array_filter($gs, static fn ($g) => !$g['open'] && $g['aria'] !== 'false');
            if ($r['code'] !== 200 || $open !== [$wantGroup] || $active !== $wantActive || $closedAria !== []) {
                $bad[] = "{$path} [{$r['code']}] mở=" . implode(',', $open) . ' active=' . implode(',', $active);
            }
        }

        return $bad;
    };
    $bad = $pageCheck('bql', $pages);
    check($G, 'Mỗi trang (kể cả trang con: chi tiết tòa / công tơ / kỳ, tính phí, tạo quy tắc) chỉ mở sẵn đúng nhóm cha + highlight đúng mục (' . count($pages) . ' trang)', $bad === [], implode(' | ', $bad));

    // ================================================================ 3. Kế toán / Kỹ thuật: cùng kiểu nhóm thu gọn, giữ nguyên cấu trúc menu riêng
    $roles = [
        'ketoan01' => [
            'structure' => [
                ['Tính phí & chốt kỳ', ['Tính phí theo kỳ /periods']],
                ['Thanh toán', ['Theo dõi thanh toán /payments', 'Cần kiểm tra /payments/review']],
                ['Thông báo', ['Hộp thư đi /email-notifications']],
                ['Báo cáo', ['Báo cáo tiện ích /reports/utility']],
                ['Biểu giá & phí', ['Biểu giá điện/nước /tariffs', 'Phí cố định & gửi xe /fees', 'Đề xuất biểu giá /tariff-proposals']],
                ['Tra cứu', ['Căn hộ /apartments', 'Tra cứu chỉ số /readings']],
            ],
            'pages' => [
                '/periods' => ['Tính phí & chốt kỳ', ['Tính phí theo kỳ']],
                "/periods/{$periodId}" => ['Tính phí & chốt kỳ', ['Tính phí theo kỳ']],
                "/periods/{$periodId}/billing" => ['Tính phí & chốt kỳ', ['Tính phí theo kỳ']],
                '/payments' => ['Thanh toán', ['Theo dõi thanh toán']],
                '/payments/review' => ['Thanh toán', ['Cần kiểm tra']],
                '/email-notifications' => ['Thông báo', ['Hộp thư đi']],
                '/reports/utility' => ['Báo cáo', ['Báo cáo tiện ích']],
                '/tariffs' => ['Biểu giá & phí', ['Biểu giá điện/nước']],
                '/fees' => ['Biểu giá & phí', ['Phí cố định & gửi xe']],
                '/tariff-proposals' => ['Biểu giá & phí', ['Đề xuất biểu giá']],
                '/apartments' => ['Tra cứu', ['Căn hộ']],
                '/readings' => ['Tra cứu', ['Tra cứu chỉ số']],
            ],
            'sums' => ['Thanh toán' => 1, 'Thông báo' => 1],
            'hidden' => ['/buildings"', '/zones"', '/meters"', '/targets"', '/alerts"', '/alert-rules"', '/users"', '/baselines"'],
            'browser' => ['a' => 'Biểu giá & phí', 'b' => 'Tra cứu', 'pay' => 'Thanh toán', 'sub' => "/periods/{$periodId}/billing", 'subGroup' => 'Tính phí & chốt kỳ', 'subActive' => ['Tính phí theo kỳ']],
        ],
        'kythuat01' => [
            'structure' => [
                ['Danh mục', ['Tòa nhà /buildings', 'Khu vực /zones', 'Căn hộ /apartments', 'Công tơ /meters']],
                ['Chỉ số công tơ', ['Kỳ ghi chỉ số /periods', 'Tra cứu chỉ số /readings']],
                ['Theo dõi & phân tích', ['Mục tiêu & theo dõi /targets', 'Cảnh báo /alerts', 'Quy tắc ngưỡng /alert-rules']],
                ['Báo cáo', ['Báo cáo tiện ích /reports/utility']],
            ],
            'pages' => [
                '/buildings' => ['Danh mục', ['Tòa nhà']],
                "/buildings/{$buildingId}" => ['Danh mục', ['Tòa nhà']],
                '/zones' => ['Danh mục', ['Khu vực']],
                '/apartments' => ['Danh mục', ['Căn hộ']],
                "/meters/{$meterId}" => ['Danh mục', ['Công tơ']],
                '/periods' => ['Chỉ số công tơ', ['Kỳ ghi chỉ số']],
                "/periods/{$periodId}" => ['Chỉ số công tơ', ['Kỳ ghi chỉ số']],
                '/readings' => ['Chỉ số công tơ', ['Tra cứu chỉ số']],
                '/targets' => ['Theo dõi & phân tích', ['Mục tiêu & theo dõi']],
                '/alerts' => ['Theo dõi & phân tích', ['Cảnh báo']],
                '/alert-rules' => ['Theo dõi & phân tích', ['Quy tắc ngưỡng']],
                '/reports/utility' => ['Báo cáo', ['Báo cáo tiện ích']],
            ],
            'sums' => null, // Kỹ thuật: badge "Kỳ ghi chỉ số" = công tơ chưa ghi ở kỳ mở -> tổng nhóm phải bằng badge mục
            'hidden' => ['/payments"', '/email-notifications"', '/tariffs"', '/fees"', '/users"', '/baselines"', '/comparisons"', '/recommendation-rules"', 'Tính phí'],
            'browser' => ['a' => 'Danh mục', 'b' => 'Theo dõi & phân tích', 'pay' => 'Chỉ số công tơ', 'sub' => "/periods/{$periodId}", 'subGroup' => 'Chỉ số công tơ', 'subActive' => ['Kỳ ghi chỉ số']],
        ],
    ];
    foreach ($roles as $user => $cfg) {
        $G = $user === 'ketoan01' ? 'KẾ TOÁN' : 'KỸ THUẬT';
        check($G, "Đăng nhập {$user}", login($base, $jar($user), $user));
        $body = http($jar($user), 'GET', $base . '/dashboard')['body'];
        $gs = navGroups($body);
        $structure = array_map(static fn ($g) => [$g['label'], array_map(static fn ($i) => $i['label'] . ' ' . substr($i['href'], strlen($base)), $g['items'])], $gs);
        preg_match('#<aside[^>]*>(.*?)</aside>#s', $body, $aside);
        check($G, 'Cấu trúc menu giữ nguyên (nhóm, thứ tự, mục, URL), nay là nhóm thu gọn có nút + chevron; không lộ mục ẩn', $structure === $cfg['structure']
            && count(array_filter($gs, static fn ($g) => $g['controls'])) === count($cfg['structure']) && substr_count($body, '<svg class="nav-group__chevron" width="14" height="14"') === count($cfg['structure'])
            && !str_contains($aside[1] ?? '', 'app-sidebar__section-label')
            && array_filter($cfg['hidden'], static fn ($h) => str_contains($aside[1] ?? '', $h)) === [], json_encode($structure, JSON_UNESCAPED_UNICODE));
        check($G, '/dashboard: mọi nhóm đóng', array_filter($gs, static fn ($g) => $g['open'] || $g['aria'] !== 'false') === []);
        $sums = array_filter(array_column($gs, 'sum', 'label'), static fn ($v) => $v !== null);
        $childTotals = array_filter(array_map(static fn ($g) => array_sum(array_map(static fn ($i) => (int) $i['badge'], $g['items'])), array_column($gs, null, 'label')));
        check($G, 'Badge mục con giữ nguyên; nhóm đóng hiện tổng = tổng badge các mục, nhóm không có việc thì ẩn tổng', $sums === $childTotals && $sums !== []
            && ($cfg['sums'] === null || $sums === $cfg['sums']), json_encode([$sums, $childTotals], JSON_UNESCAPED_UNICODE));
        $bad = $pageCheck($user, $cfg['pages']);
        check($G, 'Mỗi trang / trang con chỉ mở sẵn đúng nhóm cha + highlight đúng mục (' . count($cfg['pages']) . ' trang)', $bad === [], implode(' | ', $bad));
    }

    // ================================================================ 4. Chrome: thao tác thật (cả 3 vai trò)
    $chrome = findChrome();
    $browser = ['bql01' => ['a' => 'Danh mục', 'b' => 'Biểu giá & phí', 'pay' => 'Thanh toán', 'sub' => "/periods/{$periodId}", 'subGroup' => 'Vận hành & chỉ số', 'subActive' => ['Kỳ ghi chỉ số']]]
        + array_map(static fn ($c) => $c['browser'], $roles)
        // Song ngữ: cùng kịch bản sau khi đổi sang English bằng menu 🌐 (nhãn nhóm / mục tiếng Anh)
        + ['bql01@en' => ['a' => 'Master data', 'b' => 'Tariffs & fees', 'pay' => 'Payments', 'sub' => "/periods/{$periodId}", 'subGroup' => 'Operations & readings', 'subActive' => ['Reading periods']],
           'ketoan01@en' => ['a' => 'Tariffs & fees', 'b' => 'Lookup', 'pay' => 'Payments', 'sub' => "/periods/{$periodId}/billing", 'subGroup' => 'Billing & period close', 'subActive' => ['Billing by period']],
           'kythuat01@en' => ['a' => 'Master data', 'b' => 'Monitoring & analytics', 'pay' => 'Meter readings', 'sub' => "/periods/{$periodId}", 'subGroup' => 'Meter readings', 'subActive' => ['Reading periods']]];
    foreach ($browser as $runKey => $b) {
        [$user, $lang] = explode('@', $runKey . '@');
        $G = 'CHROME ' . $runKey;
        if ($chrome === null) {
            check($G, 'SKIPPED: không tìm thấy Chrome/Chromium (đặt CHROME_BIN)', true);
            continue;
        }
        $r = harness($chrome, $base . '/__sidebar_harness.html?' . http_build_query(['lang' => $lang, 'user' => $user, 'a' => $b['a'], 'b' => $b['b'], 'pay' => $b['pay'], 'sub' => $b['sub'], 'subGroup' => $b['subGroup']]));
        if ($r === null || isset($r['error']) || count($r['steps'] ?? []) !== 7) {
            check($G, 'chạy được kịch bản trong Chrome', false, json_encode($r, JSON_UNESCAPED_UNICODE));
            continue;
        }
        if ($lang !== '') {
            check($G, 'Đổi sang English bằng menu 🌐: tải lại đúng trang đang xem (/dashboard), <html lang="en">', ($r['langAfterSwitch'] ?? null) === ['html' => 'en', 'url' => '/dashboard'], json_encode($r['langAfterSwitch'] ?? null));
        }
        $d = $r['dashboard'];
        $nGroups = $user === 'bql01' ? 8 : count($roles[$user]['structure']);
        $closedOk = array_filter($d['groups'], static fn ($g) => $g['open'] || $g['aria'] !== 'false' || $g['panelH'] !== 0 || $g['visible'] !== 'hidden') === [];
        check($G, "Tải /dashboard: {$nGroups} nhóm đóng (cao 0, ẩn), không Tab tới mục trong nhóm đóng; chevron \">\" (không xoay)", count($d['groups']) === $nGroups && $closedOk
            && $d['tabbableInClosed'] === 0 && array_filter($d['groups'], static fn ($g) => $g['chevron'] !== 'none') === [], json_encode($d, JSON_UNESCAPED_UNICODE));
        $o = $r['openCatalog']['s'];
        check($G, "Bấm \"{$b['a']}\": mở ngay (aria-expanded=true), có animation, panel cao đúng nội dung, mục hiện / Tab được, chevron xoay 90° (\"v\")",
            $o['immediate'] === ['open' => true, 'aria' => 'true'] && in_array('grid-template-rows', $o['animated'], true) && in_array('transform', $o['animated'], true) && $o['panelH'] > 70 && abs($o['panelH'] - $o['innerH']) <= 1
            && $o['visible'] === 'visible' && $o['chevron'] === 'matrix(0, 1, -1, 0, 0, 0)', json_encode($o, JSON_UNESCAPED_UNICODE));
        check($G, "Mở thêm \"{$b['b']}\": nhóm đã mở vẫn mở (mở nhiều nhóm cùng lúc)", $r['openPricing']['s']['open'] && in_array('grid-template-rows', $r['openPricing']['s']['animated'], true) && $r['openPricing']['catalogStillOpen']);
        $c = $r['closeCatalog']['s'];
        check($G, "Bấm lại \"{$b['a']}\": đóng có animation (cao 0, ẩn, aria=false, chevron về \">\"), nhóm khác không đổi", !$c['open'] && $c['aria'] === 'false' && in_array('grid-template-rows', $c['animated'], true)
            && $c['panelH'] === 0 && $c['visible'] === 'hidden' && $c['chevron'] === 'none' && $r['closeCatalog']['pricingStillOpen'], json_encode($c, JSON_UNESCAPED_UNICODE));
        check($G, 'Layout không nhảy khi mở / đóng nhóm (độ rộng sidebar, vị trí nội dung không đổi)', $r['openCatalog']['layout'] === $d['layout'] && $r['closeCatalog']['layout'] === $d['layout']
            && $r['subPage']['layout'] === $d['layout'], json_encode([$d['layout'], $r['closeCatalog']['layout']]));
        $payGroup = array_values(array_filter($d['groups'], static fn ($g) => $g['label'] === $b['pay']))[0] ?? [];
        $p = $r['openPayments']['s'];
        check($G, "Badge: nhóm \"{$b['pay']}\" đóng hiện tổng; mở ra thì ẩn tổng, badge của mục hiện", ($payGroup['sum'] ?? null) !== null && (int) $payGroup['sum'] > 0 && $p['open'] && $p['sum'] === null
            && $r['openPayments']['childBadgeVisible'], json_encode([$payGroup, $p], JSON_UNESCAPED_UNICODE));
        check($G, 'Tải lại /dashboard sau khi tự mở nhóm: về mặc định (không nhóm nào mở)', $r['reloadDashboard']['open'] === 0);
        $s = $r['subPage'];
        check($G, "Trang con {$b['sub']}: chỉ \"{$b['subGroup']}\" mở sẵn, cao đủ ngay khi tải (không chạy animation), highlight đúng mục",
            $s['s']['open'] && $s['others'] === 0 && $s['runningAnimations'] === 0 && abs($s['s']['panelH'] - $s['s']['innerH']) <= 1 && $s['s']['panelH'] > 30
            && $s['s']['active'] === $b['subActive'], json_encode($s, JSON_UNESCAPED_UNICODE));
    }
} catch (Throwable $e) {
    check('FATAL', get_class($e) . ': ' . $e->getMessage(), false, $e->getFile() . ':' . $e->getLine());
}

foreach (['bql', 'ketoan01', 'kythuat01'] as $who) {
    @unlink($jar($who));
}
$pass = 0;
$fail = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . str_pad($g, 9) . $n . ($ok || $d === '' ? '' : "\n        -> {$d}") . "\n";
}
echo "\n{$pass}/" . ($pass + $fail) . ($fail === 0 ? ' PASS' : ' - CÓ LỖI') . " (sidebar thu gọn 3 vai trò qua {$base}, db={$testDb})\n";
exit($fail === 0 ? 0 : 1);
