<?php

declare(strict_types=1);

/**
 * Song ngữ giao diện vi / en - nền tảng i18n + topbar / sidebar / nhãn dùng chung. DB TẠM + php -S.
 *   php tests/i18n_test.php <base_url> <db_tạm>      (gọi qua tests/run_i18n_tests.sh)
 * 1) Từ điển: vi.php / en.php cùng bộ khoá, không rỗng, cùng placeholder; en phủ hết nhãn ENUM của config/master_data.php.
 * 2) Lang / helper: mặc định vi, giá trị session lạ -> vi, thiếu khoá en -> vi -> khoá; ":to" không ăn vào ":total"; label_of vi = config.
 * 3) LocaleController::safeBack chặn open redirect.
 * 4) HTTP cả 3 vai trò: đổi VI -> EN -> VI giữ nguyên URL (cả query), lưu session, giữ khi chuyển trang; sidebar tiếng Anh cùng cấu trúc /
 *    URL / nhóm mở / mục highlight / badge với tiếng Việt; dữ liệu nghiệp vụ (mã, tên) không bị dịch; về VI thì giao diện như cũ.
 * 5) Final audit: messages_en.php + message_t() / rule_condition_t() (VI nguyên văn, EN dịch), crawler EN mọi trang GET của 3 vai trò
 *    (chỉ còn dữ liệu DB / ngoại lệ B), lỗi form + flash ở EN, phiếu in giữ tiếng Việt.
 */

[$_, $base, $testDb] = $argv + [null, '', ''];
if ($base === '' || $testDb === '' || in_array($testDb, ['energy_dss', 'energy_dss_v2'], true)) {
    fwrite(STDERR, "Dùng: php tests/i18n_test.php <base_url> <db_tạm>\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_ENV['DB_DATABASE'] = $testDb;
App\Core\Config::set('database.database', $testDb);
$pdo = App\Core\Database::connection();
$base = rtrim($base, '/');
$jar = static fn (string $who): string => sys_get_temp_dir() . "/i18n_{$who}_" . getmypid() . '.txt';

use App\Controllers\LocaleController;
use App\Core\Lang;

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
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size)];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function login(string $base, string $jar, string $user): bool
{
    @unlink($jar);

    return http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom(http($jar, 'GET', $base . '/login')['body']), 'username' => $user, 'password' => 'Password@123'])['code'] === 302;
}

/** Khoá phẳng "a.b.c" => giá trị của một từ điển */
function flatKeys(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $out += is_array($v) ? flatKeys($v, $prefix . $k . '.') : [$prefix . $k => $v];
    }

    return $out;
}

/** Ảnh chụp giao diện chung: topbar + sidebar (nhóm, mục, URL, nhóm mở, highlight, badge) */
function chrome(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $x = new DOMXPath($dom);
    $txt = static fn (string $q): string => trim((string) preg_replace('/\s+/u', ' ', $x->query($q)->item(0)?->textContent ?? ''));
    $groups = [];
    foreach ($x->query('//aside[@id="appSidebar"]//div[@data-nav-group]') as $g) {
        $items = [];
        foreach ($x->query('.//div[contains(@class,"nav-group__inner")]/a', $g) as $a) {
            $badge = $x->query('.//span[@data-issue-count]/span[@aria-hidden] | .//span[@data-payment-review-count]', $a)->item(0);
            $items[] = ['label' => trim((string) preg_replace('/\s+/u', ' ', $x->query('./text()', $a)->item(1)?->textContent ?? '')), 'href' => $a->getAttribute('href'),
                'active' => str_contains($a->getAttribute('class'), 'is-active'), 'badge' => $badge?->textContent];
        }
        $groups[] = ['label' => trim($x->query('.//span[contains(@class,"nav-group__label")]', $g)->item(0)->textContent),
            'open' => str_contains($g->getAttribute('class'), 'is-open'), 'items' => $items];
    }
    $active = $x->query('//button[contains(@class,"lang-switch__option") and @aria-current="true"]')->item(0);

    return [
        'lang' => $dom->documentElement?->getAttribute('lang') ?? '',
        'title' => $txt('//div[contains(@class,"app-header__title")]'),
        'role' => $txt('//span[contains(@class,"badge--role")]'),
        'code' => $txt('//span[contains(@class,"lang-switch__code")]'),
        'activeLang' => $active?->getAttribute('value'),
        'logout' => $txt('//form[contains(@class,"app-header__logout")]/button'),
        'back' => $x->query('//form[contains(@class,"lang-switch__menu")]/input[@name="back"]')->item(0)?->getAttribute('value'),
        'dashboard' => trim((string) preg_replace('/\s+/u', ' ', $x->query('//aside[@id="appSidebar"]//nav/a[1]/text()')->item(1)?->textContent ?? '')),
        'groups' => $groups,
    ];
}

/** Bỏ nhãn chữ, giữ cấu trúc: nhóm mở, URL, highlight, badge */
function shape(array $c): array
{
    return array_map(static fn ($g) => [$g['open'], array_map(static fn ($i) => [$i['href'], $i['active'], $i['badge']], $g['items'])], $c['groups']);
}

function switchLang(string $base, string $jar, string $html, string $locale): array
{
    preg_match('/name="back" value="([^"]*)"/', $html, $m);

    return http($jar, 'POST', $base . '/locale', ['csrf_token' => csrfFrom($html), 'locale' => $locale, 'back' => html_entity_decode($m[1] ?? '')]);
}

try {
    // ================================================================ 1. Từ điển
    $G = 'DICT';
    $vi = require BASE_PATH . '/resources/lang/vi.php';
    $en = require BASE_PATH . '/resources/lang/en.php';
    $fv = flatKeys($vi);
    $fe = array_filter(flatKeys($en), static fn ($k) => !str_starts_with($k, 'labels.'), ARRAY_FILTER_USE_KEY);
    check($G, 'vi.php và en.php cùng bộ khoá giao diện (' . count($fv) . ' khoá)', array_keys($fv) === array_keys($fe),
        json_encode(['thiếu en' => array_values(array_diff(array_keys($fv), array_keys($fe))), 'thừa en' => array_values(array_diff(array_keys($fe), array_keys($fv)))], JSON_UNESCAPED_UNICODE));
    // cùng TẬP placeholder (thứ tự từ có thể khác giữa hai ngôn ngữ, vd ":measure :utility" / ":utility :measure")
    $ph = static function (string $s): array {
        $m = preg_match_all('/:[a-z_]+/', $s, $mm) ? array_values(array_unique($mm[0])) : [];
        sort($m);

        return $m;
    };
    $badPh = array_keys(array_filter($fv, static fn ($v, $k) => trim((string) $v) === '' || trim((string) ($fe[$k] ?? '')) === '' || $ph((string) $v) !== $ph((string) ($fe[$k] ?? '')), ARRAY_FILTER_USE_BOTH));
    check($G, 'Không bản dịch rỗng; placeholder (:n, :from…) giống nhau giữa vi / en', $badPh === [], implode(', ', $badPh));
    $missingLabels = [];
    foreach (config('master_data') as $group => $values) {
        if (is_array($values)) {
            foreach (array_keys($values) as $value) {
                if (!isset($en['labels'][$group][$value])) {
                    $missingLabels[] = "{$group}.{$value}";
                }
            }
        }
    }
    check($G, 'en.php có nhãn tiếng Anh cho mọi giá trị ENUM của config/master_data.php (label_of)', $missingLabels === [], implode(', ', $missingLabels));

    $statusMaps = ['readings.period_status', 'readings.reading_status', 'readings.worklist_status', 'readings.import_status', 'readings.reading_source', 'alerts.status', 'alerts.priority',
        'alerts.case_status', 'alerts.conclusion', 'alerts.source', 'alerts.alert_type', 'alerts.rule_status', 'alerts.measure_type', 'alerts.compare_base', 'alerts.rule_scope_type', 'alerts.utility_label',
        'pricing.tariff_lifecycle', 'pricing.proposal_status', 'pricing.tariff_kind', 'pricing.fee_type', 'billing.charge_status', 'billing.fee_group', 'analytics.baseline_lifecycle',
        'analytics.target_status', 'analytics.data_status', 'analytics.scope_type', 'analytics.baseline_method', 'recommendations.rule_status', 'recommendations.condition_type',
        'j16.tech_results', 'j16.phone_results', 'j16.resident_responses', 'j16.conclusions'];
    $missingStatus = [];
    foreach ($statusMaps as $map) {
        foreach (array_keys((array) config($map)) as $code) {
            if (!Lang::has("labels.{$map}.{$code}", 'en')) {
                $missingStatus[] = "{$map}.{$code}";
            }
        }
    }
    foreach (['payment.status' => App\Services\PaymentStatusService::LABELS, 'payment.match' => App\Services\PaymentStatusService::MATCH_LABELS,
        'email.status' => ['sent' => 1, 'failed' => 1, 'pending' => 1], 'report.charge_state' => App\Services\UtilityReportService::stateLabels(), 'billing.consistency' => array_flip(['current', 'stale', 'stale_error', 'locked', 'not_calculated', 'missing', 'error'])] as $map => $codes) {
        foreach (array_keys($codes) as $code) {
            if (!Lang::has("labels.{$map}.{$code}", 'en')) {
                $missingStatus[] = "{$map}.{$code}";
            }
        }
    }
    check($G, 'Batch 1: en.php có nhãn tiếng Anh cho MỌI mã của ' . (count($statusMaps) + 4) . ' map trạng thái dùng chung (khoá = mã lưu DB, không đổi)', $missingStatus === [], implode(', ', $missingStatus));
    // Chống "dịch nhầm dữ liệu lưu DB": service / email KHÔNG được dùng helper phụ thuộc ngôn ngữ (ngoại lệ duy nhất: utilityLabel() chỉ được view gọi)
    $leaks = [];
    foreach (array_merge(glob(BASE_PATH . '/app/Services/*.php'), glob(BASE_PATH . '/app/Repositories/*.php'), glob(BASE_PATH . '/resources/views/emails/*.php')) as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $i => $tok) {
            if (is_array($tok) && $tok[0] === T_STRING && in_array($tok[1], ['t', 'label_of', 'config_label', 'config_labels', 'label_t', 'locale', 'message_t', 'rule_condition_t'], true)) { // label_source() (luôn tiếng Việt) được phép
                $line = $tok[2];
                if (!(basename($file) === 'NoUsageConsumptionService.php' && $tok[1] === 'label_of')) {
                    $leaks[] = basename($file) . ':' . $line . ' ' . $tok[1] . '()';
                }
            }
        }
    }
    foreach (glob(BASE_PATH . '/app/Controllers/*.php') as $file) { // controller giữ thông điệp gốc tiếng Việt; chỉ view dịch khi hiển thị
        foreach (token_get_all((string) file_get_contents($file)) as $tok) {
            if (is_array($tok) && $tok[0] === T_STRING && in_array($tok[1], ['message_t', 'rule_condition_t'], true)) {
                $leaks[] = basename($file) . ':' . $tok[2] . ' ' . $tok[1] . '()';
            }
        }
    }
    $utilCallers = array_filter(array_merge(glob(BASE_PATH . '/app/*/*.php')), static fn ($f) => !str_ends_with($f, 'NoUsageConsumptionService.php') && str_contains((string) file_get_contents($f), 'utilityLabel('));
    check($G, 'Dữ liệu lưu DB không phụ thuộc ngôn ngữ: service / repository / mẫu email không gọi t() / label_of() / config_label() / message_t() / rule_condition_t(); controller không dịch thông điệp (utilityLabel() chỉ view dùng)',
        $leaks === [] && $utilCallers === [], implode(', ', array_merge($leaks, $utilCallers)));

    // ================================================================ 2. Lang / helper
    $G = 'LANG';
    $_SESSION = [];
    check($G, 'Mặc định vi khi session chưa có ngôn ngữ', Lang::locale() === 'vi' && t('topbar.logout') === 'Đăng xuất');
    $_SESSION['locale'] = 'fr';
    check($G, 'Giá trị session lạ ("fr") -> vi', Lang::locale() === 'vi');
    $_SESSION['locale'] = 'en';
    check($G, 'en: t() dịch; ví dụ yêu cầu: Decision Dashboard / Management (BQL) / Accounting / Technical / Payment Tracking / Needs Review / Outbox / Logout',
        [t('page.dashboard'), label_of('user_role', 'bql'), label_of('user_role', 'ke_toan'), label_of('user_role', 'ky_thuat'), t('menu.payments'), t('menu.payment_review'), t('menu.outbox'), t('topbar.logout')]
        === ['Decision Dashboard', 'Management (BQL)', 'Accounting', 'Technical', 'Payment Tracking', 'Needs Review', 'Outbox', 'Logout']);
    check($G, 'Thiếu khoá: en -> vi -> trả lại khoá; ":to" không ăn vào ":total"', Lang::get('khong.ton.tai') === 'khong.ton.tai'
        && t('common.pagination.showing', ['from' => 1, 'to' => 20, 'total' => 135]) === 'Showing 1–20 of 135 records'
        && Lang::get('common.pagination.showing', ['from' => 1, 'to' => 20, 'total' => 135], 'vi') === 'Hiển thị 1–20 / 135 bản ghi');
    $_SESSION['locale'] = 'vi';
    $viLabels = [];
    foreach (config('master_data') as $group => $values) {
        if (is_array($values)) {
            foreach ($values as $value => $label) {
                $viLabels[] = label_of($group, (string) $value) === $label;
            }
        }
    }
    check($G, 'vi: label_of trả đúng nhãn config như trước (nguồn tiếng Việt không đổi)', !in_array(false, $viLabels, true) && $viLabels !== []);
    $_SESSION = [];

    // ================================================================ 3. Chống open redirect
    $G = 'SAFEBACK';
    $cases = ['/payments?period_id=1' => '/payments?period_id=1', '/periods/3/billing' => '/periods/3/billing', '' => '/dashboard', 'dashboard' => '/dashboard',
        '//evil.example' => '/dashboard', 'https://evil.example' => '/dashboard', '/\\evil' => '/dashboard', "/a\r\nX: y" => '/dashboard', '/http://evil' => '/dashboard',
        'javascript:alert(1)' => '/dashboard', '/' . str_repeat('a', 2100) => '/dashboard'];
    $badBack = array_filter(array_keys($cases), static fn ($in) => LocaleController::safeBack((string) $in) !== $cases[$in]);
    check($G, 'safeBack: chỉ nhận đường dẫn nội bộ (giữ query), còn lại -> /dashboard (' . count($cases) . ' trường hợp)', $badBack === [], json_encode(array_values($badBack)));

    // ================================================================ 4. HTTP cả 3 vai trò
    $pdo->exec("INSERT INTO payment_transactions (transaction_id, source, amount, transfer_content, payer_name, received_at, apartment_charge_id, match_status, raw_payload)
        VALUES ('I18N-REVIEW-1', 'mock_bank', 1000, 'EDSS999999', 'Test', NOW(), NULL, 'unknown_ref', '{}')");
    $periodId = (int) $pdo->query('SELECT MAX(id) FROM billing_periods')->fetchColumn();
    $meterId = (int) $pdo->query('SELECT MIN(id) FROM meters')->fetchColumn();
    $codes = $pdo->query('SELECT code FROM buildings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $runs = [
        'bql01' => ['page' => "/periods/{$periodId}/billing?tab=1", 'other' => '/payments', 'role' => ['Ban quản lý (BQL)', 'Management (BQL)'],
            'groups' => ['System administration', 'Master data', 'Tariffs & fees', 'Operations & readings', 'Monitoring & analytics', 'Payments', 'Notifications', 'Reports'],
            'otherTitle' => ['Thanh toán', 'Payments']],
        'ketoan01' => ['page' => "/periods/{$periodId}/billing", 'other' => '/payments/review', 'role' => ['Kế toán', 'Accounting'],
            'groups' => ['Billing & period close', 'Payments', 'Notifications', 'Reports', 'Tariffs & fees', 'Lookup'], 'otherTitle' => ['Giao dịch cần kiểm tra', 'Transactions needing review']],
        'kythuat01' => ['page' => "/meters/{$meterId}", 'other' => '/alerts', 'role' => ['Kỹ thuật', 'Technical'],
            'groups' => ['Master data', 'Meter readings', 'Monitoring & analytics', 'Reports'], 'otherTitle' => ['Cảnh báo', 'Alerts']],
    ];
    foreach ($runs as $user => $cfg) {
        $G = strtoupper($user);
        check($G, "Đăng nhập {$user}", login($base, $jar($user), $user));
        $viHtml = http($jar($user), 'GET', $base . $cfg['page'])['body'];
        $v = chrome($viHtml);
        check($G, 'Mặc định tiếng Việt: <html lang="vi">, nút 🌐 "VI" (đánh dấu Tiếng Việt), vai trò / Đăng xuất tiếng Việt; form đổi ngôn ngữ mang đúng trang hiện tại',
            $v['lang'] === 'vi' && $v['code'] === 'VI' && $v['activeLang'] === 'vi' && $v['role'] === $cfg['role'][0] && $v['logout'] === 'Đăng xuất' && $v['back'] === $cfg['page'], json_encode($v, JSON_UNESCAPED_UNICODE));

        $sw = switchLang($base, $jar($user), $viHtml, 'en');
        check($G, 'VI -> EN: quay lại đúng URL đang xem (cả query)', $sw['code'] === 302 && $sw['location'] === $base . $cfg['page'], $sw['code'] . ' ' . $sw['location']);
        $enHtml = http($jar($user), 'GET', $base . $cfg['page'])['body'];
        $e = chrome($enHtml);
        check($G, 'EN: <html lang="en">, nút "EN" (đánh dấu English), vai trò / Logout / Dashboard / tên nhóm sidebar tiếng Anh',
            $e['lang'] === 'en' && $e['code'] === 'EN' && $e['activeLang'] === 'en' && $e['role'] === $cfg['role'][1] && $e['logout'] === 'Logout' && $e['dashboard'] === 'Dashboard'
            && array_column($e['groups'], 'label') === $cfg['groups'], json_encode(array_diff_key($e, ['groups' => 1]) + ['groups' => array_column($e['groups'], 'label')], JSON_UNESCAPED_UNICODE));
        $viLabels = array_merge(...array_map(static fn ($g) => array_column($g['items'], 'label'), $v['groups']));
        $enLabels = array_merge(...array_map(static fn ($g) => array_column($g['items'], 'label'), $e['groups']));
        check($G, 'Sidebar EN giữ nguyên hành vi: cùng nhóm mở sẵn, cùng URL / thứ tự mục, cùng mục highlight, cùng badge; mọi nhãn mục đã dịch',
            shape($e) === shape($v) && count($enLabels) === count($viLabels) && array_filter($enLabels, static fn ($l) => preg_match('/[À-ỹđĐ]/u', $l)) === [],
            json_encode([shape($v), shape($e), $enLabels], JSON_UNESCAPED_UNICODE));
        $o = chrome(http($jar($user), 'GET', $base . $cfg['other'])['body']);
        check($G, 'EN giữ khi chuyển trang (lưu session): trang khác vẫn tiếng Anh, tiêu đề trang đã dịch', $o['lang'] === 'en' && $o['title'] === $cfg['otherTitle'][1], json_encode([$o['lang'], $o['title']], JSON_UNESCAPED_UNICODE));

        $back = switchLang($base, $jar($user), $enHtml, 'vi');
        $v2Html = http($jar($user), 'GET', $base . $cfg['page'])['body'];
        $v2 = chrome($v2Html);
        $norm = static fn (string $h): string => (string) preg_replace('/\s+/', ' ', preg_replace('#.*(<aside.*?</aside>).*#s', '$1', $h));
        check($G, 'EN -> VI: quay lại đúng URL; giao diện tiếng Việt như ban đầu (sidebar giống hệt)', $back['code'] === 302 && $back['location'] === $base . $cfg['page']
            && $v2['lang'] === 'vi' && $v2['role'] === $cfg['role'][0] && $norm($v2Html) === $norm($viHtml), $back['location']);
    }

    // Batch 1 + 2: mọi trang đích sidebar ở tiếng Anh - tiêu đề, breadcrumb, badge trạng thái không còn chữ Việt; không ghi DB khi đổi ngôn ngữ / duyệt
    $vnRe = '/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu';
    $textOf = static function (string $html, string $xpath): array {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $out = [];
        foreach ((new DOMXPath($dom))->query($xpath) as $node) {
            $out[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }

        return $out;
    };
    $dbSum = static fn (): string => md5(json_encode($pdo->query('CHECKSUM TABLE apartment_charges, billing_periods, meter_readings, alerts, payment_transactions, email_notifications, audit_logs, users, tariffs')->fetchAll(PDO::FETCH_NUM)));
    foreach (['bql01', 'ketoan01', 'kythuat01'] as $user) {
        $G = strtoupper($user) . ' EN';
        $dash = http($jar($user), 'GET', $base . '/dashboard')['body'];
        $sumBefore = $dbSum();
        switchLang($base, $jar($user), $dash, 'en');
        preg_match_all('#href="' . preg_quote($base, '#') . '(/[^"]*)" class="app-sidebar__link#', $dash, $mm);
        $bad = [];
        $badges = 0;
        foreach (array_unique(array_merge(['/dashboard'], $mm[1])) as $path) {
            $r = http($jar($user), 'GET', $base . $path);
            $h1 = $textOf($r['body'], '//h1[contains(@class,"page-title")]');
            $crumbs = $textOf($r['body'], '//nav[contains(@class,"breadcrumb")]');
            $badgeTexts = $textOf($r['body'], '//main//span[contains(concat(" ", normalize-space(@class), " "), " badge ")]');
            $badges += count($badgeTexts);
            $viLeft = array_filter(array_merge($h1, $crumbs, $badgeTexts), static fn ($t) => preg_match($vnRe, $t));
            if ($r['code'] !== 200 || $h1 === [] || $viLeft !== []) {
                $bad[] = "{$path} [{$r['code']}] " . implode(' | ', $viLeft);
            }
        }
        check($G, 'EN: mọi trang đích sidebar (' . count(array_unique(array_merge(['/dashboard'], $mm[1]))) . ' trang) - tiêu đề, breadcrumb, ' . $badges . ' badge trạng thái đều tiếng Anh', $bad === [], implode(' || ', $bad));
        $back = http($jar($user), 'GET', $base . '/dashboard')['body'];
        switchLang($base, $jar($user), $back, 'vi');
        check($G, 'Đổi ngôn ngữ + duyệt mọi trang đích ở EN không ghi DB (checksum bản tính / kỳ / chỉ số / cảnh báo / thanh toán / email / audit / users / biểu giá không đổi)', $dbSum() === $sumBefore);
    }
    // Badge trạng thái cụ thể: kỳ, thanh toán cần kiểm tra
    $G = 'BADGES EN';
    $p = http($jar('bql01'), 'GET', $base . '/periods')['body'];
    switchLang($base, $jar('bql01'), $p, 'en');
    $periods = http($jar('bql01'), 'GET', $base . '/periods')['body'];
    $review = http($jar('ketoan01'), 'GET', $base . '/payments/review')['body'];
    check($G, 'EN /periods: badge kỳ "Locked" / "Open" (mã DB da_khoa / mo không đổi trong URL lọc)', (bool) preg_match('#class="badge [^"]*">(Locked|Open|Reopened)</span>#', $periods)
        && !preg_match('#class="badge [^"]*">(Đã khóa|Mở)</span>#u', $periods));
    $kt = http($jar('ketoan01'), 'GET', $base . '/dashboard')['body'];
    switchLang($base, $jar('ketoan01'), $kt, 'en');
    $reviewEn = http($jar('ketoan01'), 'GET', $base . '/payments/review')['body'];
    check($G, 'EN /payments/review: badge đối soát "Unknown payment reference"; VI vẫn "Không rõ mã thanh toán"; mã giao dịch / nội dung CK (dữ liệu) giữ nguyên',
        str_contains($review, '>Không rõ mã thanh toán</span>') && str_contains($reviewEn, '>Unknown payment reference</span>') && str_contains($reviewEn, 'I18N-REVIEW-1') && str_contains($reviewEn, 'EDSS999999'));
    switchLang($base, $jar('ketoan01'), $reviewEn, 'vi');
    switchLang($base, $jar('bql01'), $periods, 'vi');

    // Batch 3: nút / lọc / tiêu đề cột / nhãn / lựa chọn / placeholder dùng chung. So VI với EN theo đúng thứ tự phần tử trên cùng trang:
    // phần tử nào có chữ VI thuộc bộ từ vựng chung thì EN phải đúng bản dịch; mọi phần tử khác giữ nguyên số lượng (cấu trúc trang không đổi).
    // Từ vựng theo LOẠI phần tử: cùng chữ Việt có thể khác nghĩa (vd "Ngừng" = nút Deactivate nhưng là trạng thái Inactive trong dropdown)
    $tagsOf = ['actions' => ['a', 'button', 'summary'], 'fields' => ['th', 'label'], 'options' => ['option'], 'placeholders' => ['placeholder']];
    $vocab = [];
    foreach ($tagsOf as $g => $tags) {
        foreach (($vi['common'][$g] ?? []) as $k => $text) {
            foreach ($tags as $tag) {
                $vocab[$tag . '|' . $text] = $en['common'][$g][$k];
            }
        }
    }
    foreach (['cancel', 'confirm'] as $k) {
        foreach (['a', 'button'] as $tag) {
            $vocab[$tag . '|' . $vi['common'][$k]] = $en['common'][$k];
        }
    }
    $elements = static function (string $html): array {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $x = new DOMXPath($dom);
        $out = [];
        foreach ($x->query('//main//th | //main//button | //main//a | //main//label | //main//option | //main//summary') as $n) {
            $out[] = $n->nodeName . '|' . trim((string) preg_replace('/\s+/u', ' ', $n->textContent));
        }
        foreach ($x->query('//main//@placeholder') as $a) {
            $out[] = 'placeholder|' . $a->value;
        }

        return $out;
    };
    $G = 'BATCH 3';
    $b3 = ['bql01' => ['/buildings', '/zones', '/apartments', '/meters', '/users', '/audit-logs', '/tariffs', '/fees', '/tariff-proposals', '/periods', "/periods/{$periodId}", '/readings', '/baselines',
            '/targets', '/alerts', '/alert-rules', '/recommendation-rules', '/email-notifications', '/reports/utility', '/buildings/create', '/meters/create', '/alert-rules/create'],
        'ketoan01' => ['/periods', "/periods/{$periodId}/billing", '/payments', '/payments/review', '/tariffs', '/fees', '/tariff-proposals', '/apartments', '/readings'],
        'kythuat01' => ['/buildings', '/zones', '/apartments', '/meters', '/periods', '/readings', '/targets', '/alerts', '/alert-rules']];
    $checked = 0;
    foreach ($b3 as $user => $paths) {
        $bad = [];
        $html = http($jar($user), 'GET', $base . '/dashboard')['body'];
        $sumB3 = $dbSum();
        foreach ($paths as $path) {
            $viEls = $elements(http($jar($user), 'GET', $base . $path)['body']);
            switchLang($base, $jar($user), $html, 'en');
            $enHtml = http($jar($user), 'GET', $base . $path)['body'];
            $enEls = $elements($enHtml);
            $html = http($jar($user), 'GET', $base . '/dashboard')['body'];
            switchLang($base, $jar($user), $html, 'vi');
            $html = http($jar($user), 'GET', $base . '/dashboard')['body'];
            if (count($viEls) !== count($enEls)) {
                $bad[] = "{$path}: số phần tử VI " . count($viEls) . ' ≠ EN ' . count($enEls);
                continue;
            }
            foreach ($viEls as $i => $v) {
                if (isset($vocab[$v])) {
                    $checked++;
                    $want = strtok($v, '|') . '|' . $vocab[$v];
                    if ($enEls[$i] !== $want) {
                        $bad[] = "{$path}: \"{$v}\" -> \"{$enEls[$i]}\" (cần \"{$want}\")";
                    }
                }
            }
        }
        check($G, "{$user}: EN - mọi nút / liên kết / tiêu đề cột / nhãn / lựa chọn / placeholder thuộc từ vựng chung đều dịch đúng (" . count($paths) . ' trang, cấu trúc trang không đổi); không ghi DB',
            $bad === [] && $dbSum() === $sumB3, implode(' || ', array_slice($bad, 0, 8)));
    }
    check($G, "Đã đối chiếu {$checked} phần tử dùng chung ở 3 vai trò (từ vựng " . (count($vi['common']['actions']) + count($vi['common']['fields']) + count($vi['common']['options']) + count($vi['common']['placeholders']) + 2) . ' mục)', $checked > 300, (string) $checked);
    // CSV xuất báo cáo: dữ liệu xuất KHÔNG theo ngôn ngữ giao diện (giữ nguyên byte)
    $exportUrl = $base . '/reports/utility/export?period_id=' . (int) $pdo->query("SELECT MIN(id) FROM billing_periods WHERE status = 'da_khoa'")->fetchColumn();
    $csvVi = http($jar('bql01'), 'GET', $exportUrl);
    $h = http($jar('bql01'), 'GET', $base . '/dashboard')['body'];
    switchLang($base, $jar('bql01'), $h, 'en');
    $csvEn = http($jar('bql01'), 'GET', $exportUrl);
    $h = http($jar('bql01'), 'GET', $base . '/dashboard')['body'];
    switchLang($base, $jar('bql01'), $h, 'vi');
    check($G, 'CSV báo cáo xuất ở EN giống hệt từng byte bản VI (tiêu đề cột / trạng thái trong file xuất không bị dịch)', $csvVi['code'] === 200 && strlen($csvVi['body']) > 50 && $csvVi['body'] === $csvEn['body'],
        $csvVi['code'] . ' / ' . strlen($csvVi['body']) . ' vs ' . strlen($csvEn['body']));

    // Batch 4: trang chi tiết / quy trình ở EN. Dữ liệu: lô import (file demo 07/2024) + chỉ số kỳ 2 + duyệt -> cảnh báo J-16 (CC-2306 điện 25) + bản tính.
    $G = 'BATCH 4';
    $kt = http($jar('kythuat01'), 'GET', $base . '/periods/2/imports/create')['body'];
    $ch = curl_init($base . '/periods/2/imports');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar('kythuat01'), CURLOPT_COOKIEFILE => $jar('kythuat01'), CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['csrf_token' => csrfFrom($kt), 'file' => new CURLFile(BASE_PATH . '/docs/demo/chi_so_07_2024_demo.xlsx')]]);
    curl_exec($ch);
    curl_close($ch);
    $batchId = (int) $pdo->query('SELECT MAX(id) FROM import_batches')->fetchColumn();
    $_SESSION = ['user_id' => 2, 'user_role' => 'ky_thuat', 'username' => 'kythuat01', 'full_name' => 'kt'];
    foreach ([1 => '1686', 2 => '232', 3 => '800', 4 => '160', 7 => '25', 8 => '0'] as $meterId => $value) {
        (new App\Services\MeterReadingService())->create(2, $meterId, ['current_reading' => $value, 'note' => '']);
    }
    $_SESSION = ['user_id' => 1, 'user_role' => 'bql', 'username' => 'bql01', 'full_name' => 'bql'];
    $items = [];
    foreach ($pdo->query('SELECT * FROM meter_readings WHERE billing_period_id = 2')->fetchAll() as $row) {
        $items[(int) $row['id']] = App\Services\MeterReadingService::versionToken($row);
    }
    (new App\Services\ReadingApprovalService())->approve(2, $items);
    $_SESSION = ['user_id' => 3, 'user_role' => 'ke_toan', 'username' => 'ketoan01', 'full_name' => 'kto'];
    (new App\Services\BillingCalculationService())->calculatePeriod(2, 1);
    $_SESSION = [];
    $j16Id = (int) $pdo->query("SELECT id FROM alerts WHERE alert_type = 'no_usage_consumption' ORDER BY id LIMIT 1")->fetchColumn();
    $chargeId = (int) $pdo->query('SELECT MIN(id) FROM apartment_charges WHERE billing_period_id = 2')->fetchColumn();
    $pendingMeter = (int) $pdo->query("SELECT m.id FROM meters m WHERE m.status = 'hoat_dong' AND m.id NOT IN (SELECT meter_id FROM meter_readings WHERE billing_period_id = 2) ORDER BY m.id LIMIT 1")->fetchColumn();
    check($G, 'Dữ liệu: lô import #' . $batchId . ', cảnh báo J-16 #' . $j16Id . ', bản tính #' . $chargeId . ', công tơ chưa ghi #' . $pendingMeter, $batchId > 0 && $j16Id > 0 && $chargeId > 0 && $pendingMeter > 0);
    $mainText = static function (string $html): string {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $x = new DOMXPath($dom);
        $t = '';
        foreach ($x->query('//main') as $n) {
            $t .= ' ' . $n->textContent;
        }
        foreach ($x->query('//main//@data-confirm-message | //main//@data-confirm-title | //main//@placeholder | //main//@title | //main//@aria-label') as $a) {
            $t .= ' ' . $a->value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $t));
    };
    // [người, trang, chữ EN phải có, chữ VI (giao diện) không còn]
    $pages4 = [
        ['bql01', '/periods/2', ['Period 07/2024', 'Active meters', 'Active meter list', 'Import batches', 'Billing & period close (FN-05)'], ['Danh sách công tơ đang hoạt động', 'Lô import', 'Chờ ghi số']],
        ['ketoan01', '/periods/1', ['Period locked', 'readings can no longer be entered'], ['Kỳ đã khóa', 'Tính phí & chốt kỳ']],
        ['kythuat01', "/periods/2/readings/create?meter_id={$pendingMeter}", ['Enter readings — period 07/2024', 'Meters to read', 'Meter information', 'Save & enter next meter →', 'Field note'], ['Thông tin công tơ', 'Lưu nháp', 'Ghi chú hiện trường']],
        ['kythuat01', "/imports/{$batchId}", ["Import batch #{$batchId}", 'Control total check (FN-04.05)', 'Row-by-row preview', 'Confirm save'], ['Xem trước từng dòng', 'Đối chiếu control total']],
        ['bql01', "/alerts/{$j16Id}", ["Alert #{$j16Id}", '1. Detection', '3. Apartment status', '4. Resident contact', '6. BQL conclusion', 'Consumption at an apartment declared as unused'], ['Phát hiện lúc', 'Liên hệ cư dân', 'Khai báo hiện tại']],
        ['ketoan01', "/charges/{$chargeId}", ['Summary (FN-05.04)', 'Electricity charges (FN-05.02)', 'History (audit)', 'Arithmetic check'], ['Tổng hợp (FN-05.04)', 'Lịch sử (audit)', 'Kiểm tra số học']],
        ['bql01', '/dashboard?period_id=2', ['Decision Dashboard', 'Open alerts', 'Utility cost', 'Alerts to handle'], ['Cảnh báo đang mở', 'Chi phí tiện ích']],
    ];
    $sum4 = $dbSum();
    $bad = [];
    $vnUi = 0;
    foreach ($pages4 as [$user, $path, $mustEn, $mustNotVi]) {
        $viPage = http($jar($user), 'GET', $base . $path)['body'];
        $home = http($jar($user), 'GET', $base . '/dashboard')['body'];
        switchLang($base, $jar($user), $home, 'en');
        $enPage = http($jar($user), 'GET', $base . $path)['body'];
        switchLang($base, $jar($user), http($jar($user), 'GET', $base . '/dashboard')['body'], 'vi');
        $t = $mainText($enPage);
        $missing = array_filter($mustEn, static fn ($s) => !str_contains($t, $s));
        $left = array_filter($mustNotVi, static fn ($s) => str_contains($t, $s));
        $viHas = array_filter($mustNotVi, static fn ($s) => !str_contains($mainText($viPage), $s));
        if ($missing !== [] || $left !== [] || $viHas !== []) {
            $bad[] = "{$path}: thiếu EN [" . implode(', ', $missing) . '] còn VI [' . implode(', ', $left) . '] VI gốc không có [' . implode(', ', $viHas) . ']';
        }
    }
    check($G, 'EN: 7 trang chi tiết / quy trình (kỳ mở + kỳ khóa, worklist, lô import, cảnh báo J-16, bản tính, dashboard) đã dịch; chữ giao diện tiếng Việt tương ứng không còn; không ghi DB',
        $bad === [] && $dbSum() === $sum4, implode(' || ', $bad));
    // Dữ liệu lưu DB vẫn tiếng Việt trên trang EN: mô tả cảnh báo J-16 + lý do kiểm tra (config j16.tech_reason = nội dung case được lưu)
    $desc = (string) $pdo->query('SELECT description FROM alerts WHERE id = ' . $j16Id)->fetchColumn();
    $home = http($jar('bql01'), 'GET', $base . '/dashboard')['body'];
    switchLang($base, $jar('bql01'), $home, 'en');
    $enAlert = http($jar('bql01'), 'GET', $base . '/alerts/' . $j16Id)['body'];
    $enHome = http($jar('bql01'), 'GET', $base . '/dashboard')['body'];
    switchLang($base, $jar('bql01'), $enHome, 'vi');
    $viHome = http($jar('bql01'), 'GET', $base . '/dashboard')['body'];
    check($G, 'B giữ nguyên ở EN: mô tả cảnh báo (DB) + "lý do kiểm tra" (config j16.tech_reason, cũng là nội dung case lưu DB) vẫn tiếng Việt', $desc !== ''
        && str_contains(html_entity_decode($enAlert, ENT_QUOTES, 'UTF-8'), $desc) && str_contains(html_entity_decode($enAlert, ENT_QUOTES, 'UTF-8'), (string) config('j16.tech_reason')));
    preg_match('#<script type="application/json" id="i18nData">(.*?)</script>#s', $enHome, $jm);
    $js = json_decode($jm[1] ?? '', true);
    check($G, 'app.js: EN có #i18nData (JSON hợp lệ: js.toast_success = Success, worklist.visible, common.confirm); VI KHÔNG có (app.js dùng chữ gốc → tiếng Việt như cũ)',
        is_array($js) && ($js['js.toast_success'] ?? null) === 'Success' && ($js['worklist.visible'] ?? null) === 'Showing :visible / :total meters' && ($js['common.confirm'] ?? null) === 'Confirm'
        && !str_contains($viHome, 'id="i18nData"') && !preg_match('/_html"/', $jm[1] ?? ''), json_encode(array_slice($js ?? [], 0, 3)));

    // Dữ liệu nghiệp vụ không bị dịch + phân trang dịch
    $G = 'DATA';
    $html = http($jar('bql01'), 'GET', $base . '/buildings')['body'];
    switchLang($base, $jar('bql01'), $html, 'en');
    $enList = http($jar('bql01'), 'GET', $base . '/buildings')['body'];
    check($G, 'EN /buildings: mã tòa nhà (dữ liệu DB) giữ nguyên; trạng thái ENUM qua label_of dịch; phân trang "Showing … records"',
        array_filter($codes, static fn ($c) => !str_contains($enList, (string) $c)) === [] && str_contains($enList, '>Active<') && (bool) preg_match('/Showing \d+–\d+ of \d+ records/u', $enList), implode(',', $codes));
    switchLang($base, $jar('bql01'), $enList, 'vi');

    // ================================================================ 5. Final audit: tầng hiển thị C (message_t / rule_condition_t) + crawler EN
    $G = 'FINAL';
    $vire = '/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu';
    $catalog = require BASE_PATH . '/resources/lang/messages_en.php';
    $badCat = [];
    foreach ($catalog as $k => $v) {
        $n = substr_count((string) $k, '{x}');
        if (!is_string($v) || trim($v) === '' || $n !== substr_count($v, '{x}') + preg_match_all('/\{\d+\}/', $v)) {
            $badCat[] = $k;
        }
    }
    check($G, 'messages_en.php: ' . count($catalog) . ' mẫu, bản dịch không rỗng, cùng số phần động {x}', $badCat === [], implode(' | ', array_slice($badCat, 0, 5)));
    Lang::force('vi');
    $viChanged = array_filter(array_keys($catalog), static fn ($k) => message_t(str_replace(['~', '{x}'], ['', 'Q7'], $k)) !== str_replace(['~', '{x}'], ['', 'Q7'], $k));
    Lang::force('en');
    $untranslated = [];
    foreach (array_keys($catalog) as $k) {
        if ($k[0] === '~') {
            continue;
        }
        $out = str_replace(['[ĐX]', 'Mã công tơ', 'đ/m²', 'đ/vehicle', 'đ/time'], '', message_t(str_replace('{x}', 'Q7', $k)));
        if (preg_match($vire, $out)) {
            $untranslated[] = $k;
        }
    }
    $sample = message_t('Dòng 5 – Điện: công tơ DH01 đã xuất hiện ở dòng 3 (trùng trong file).');
    $dataKept = message_t('Nguyễn Văn Quản') === 'Nguyễn Văn Quản' && message_t('') === '';
    Lang::force(null);
    check($G, 'message_t(): tiếng Việt trả lại NGUYÊN VĂN (mọi mẫu); tiếng Anh dịch mọi mẫu (ghép mảnh + phần động), chuỗi lạ / dữ liệu giữ nguyên',
        $viChanged === [] && $untranslated === [] && $dataKept && $sample === 'Row 5 – Electricity: meter DH01 already appears in row 3 (duplicate in the file).',
        implode(' | ', array_slice(array_merge($viChanged, $untranslated), 0, 5)) . " / {$sample}");
    $ruleBad = [];
    foreach ([['alert', 'alert_rules', [App\Services\AlertRuleService::class, 'describe']], ['recommendation', 'recommendation_rules', [App\Services\RecommendationRuleService::class, 'describe']]] as [$kind, $table, $describe]) {
        foreach ($pdo->query("SELECT * FROM {$table}")->fetchAll() as $rule) {
            Lang::force('vi');
            $vi = rule_condition_t($kind, $rule);
            Lang::force('en');
            $en = rule_condition_t($kind, $rule);
            Lang::force(null);
            if ($vi !== $describe($rule) || preg_match($vire, str_replace(' đ', '', $en))) {
                $ruleBad[] = "{$table}#{$rule['id']}: {$en}";
            }
        }
    }
    check($G, 'rule_condition_t(): tiếng Việt = đúng chuỗi describe() của service (nguồn không đổi), tiếng Anh không còn chữ Việt', $ruleBad === [], implode(' | ', $ruleBad));

    require_once __DIR__ . '/support/en_audit.php';
    $dbValues = en_audit_db_values($pdo, $testDb);
    $skip = '#/(logout|locale)\b|export|\.csv|download|\.xlsx|template|/storage/|/assets/|/mock-bank|/notice#i';
    $residue = [];
    $crawled = 0;
    $server = [];
    foreach (['bql01', 'ketoan01', 'kythuat01'] as $user) {
        $home = http($jar($user), 'GET', $base . '/dashboard')['body'];
        switchLang($base, $jar($user), $home, 'en');
        $queue = ['/dashboard'];
        $seen = [];
        $pat = [];
        while ($queue !== []) {
            $path = array_shift($queue);
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $res = http($jar($user), 'GET', $base . $path);
            if ($res['code'] !== 200) {
                if ($res['code'] >= 500) {
                    $server[] = "{$user} {$path} {$res['code']}";
                }
                continue;
            }
            $crawled++;
            foreach (en_audit_segments($res['body']) as $seg) {
                if (($r = en_audit_residue($seg, $dbValues, en_audit_allowed($path))) !== null) {
                    $residue[$r] = "{$user} {$path}";
                }
            }
            preg_match_all('/href="([^"#]+)"/', $res['body'], $mm);
            foreach ($mm[1] as $href) {
                $href = html_entity_decode($href);
                $href = str_starts_with($href, $base) ? substr($href, strlen($base)) : $href;
                if (!str_starts_with($href, '/') || preg_match($skip, $href)) {
                    continue;
                }
                $key = preg_replace(['/\d+/', '/\?.*$/'], ['N', '?q'], $href);
                if (($pat[$key] ?? 0) < 2 && !isset($seen[$href])) {
                    $pat[$key] = ($pat[$key] ?? 0) + 1;
                    $queue[] = $href;
                }
            }
        }
    }
    check($G, "Crawler EN cả 3 vai trò ({$crawled} trang GET): không còn chữ tiếng Việt ngoài dữ liệu DB / ngoại lệ đã duyệt (B, mã hệ thống), không lỗi 5xx",
        $residue === [] && $server === [] && $crawled > 50, implode(" |\n          ", array_slice(array_map(static fn ($s, $w) => "{$s} [{$w}]", array_keys($residue), $residue), 0, 40)) . implode(',', $server));

    // Luồng lỗi form + flash ở EN (bql01 đang EN sau crawler)
    $form = http($jar('bql01'), 'GET', $base . '/buildings/create')['body'];
    http($jar('bql01'), 'POST', $base . '/buildings', ['csrf_token' => csrfFrom($form)]);
    $form = http($jar('bql01'), 'GET', $base . '/buildings/create')['body'];
    preg_match_all('#<div (?:data-flash="\w+" )?class="(?:form-error|alert alert--\w+)">(.*?)</div>#su', $form, $mm);
    $msgs = array_map(static fn ($s) => html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'), $mm[1]);
    check($G, 'EN: lỗi validate + flash của form (nguồn tiếng Việt trong service / controller) hiển thị tiếng Anh',
        count($msgs) >= 2 && array_filter($msgs, static fn ($s) => preg_match($vire, $s)) === [] && in_array('The data is invalid, please check the highlighted fields.', $msgs, true), implode(' | ', $msgs));

    // Phiếu in cho cư dân luôn tiếng Việt (Lang::force('vi')) - EN và VI ra cùng nội dung
    $chargeId = (int) $pdo->query('SELECT id FROM apartment_charges ORDER BY id LIMIT 1')->fetchColumn();
    // chỉ so phần phiếu in (khung app / sidebar ngoài phiếu theo ngôn ngữ giao diện)
    $strip = static fn (string $h): string => preg_match('#<div class="page-head no-print">.*</section>#s', $h, $pm) ? $pm[0] : '';
    $enNotice = http($jar('bql01'), 'GET', $base . "/charges/{$chargeId}/notice");
    $home = http($jar('bql01'), 'GET', $base . '/dashboard')['body'];
    switchLang($base, $jar('bql01'), $home, 'vi');
    $viNotice = http($jar('bql01'), 'GET', $base . "/charges/{$chargeId}/notice");
    check($G, "Phiếu thông báo in (/charges/{$chargeId}/notice) giữ tiếng Việt khi giao diện EN (B)",
        $chargeId > 0 && $enNotice['code'] === 200 && $strip($enNotice['body']) !== '' && str_contains($strip($enNotice['body']), 'Mã thanh toán') && $strip($enNotice['body']) === $strip($viNotice['body']), "code {$enNotice['code']}");
    foreach (['ketoan01', 'kythuat01'] as $user) {
        switchLang($base, $jar($user), http($jar($user), 'GET', $base . '/dashboard')['body'], 'vi');
    }
} catch (Throwable $e) {
    check('FATAL', get_class($e) . ': ' . $e->getMessage(), false, $e->getFile() . ':' . $e->getLine());
}

foreach (['bql01', 'ketoan01', 'kythuat01'] as $who) {
    @unlink($jar($who));
}
$pass = 0;
$fail = 0;
foreach ($results as [$g, $n, $ok, $d]) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . str_pad($g, 10) . $n . ($ok || $d === '' ? '' : "\n        -> {$d}") . "\n";
}
echo "\n{$pass}/" . ($pass + $fail) . ($fail === 0 ? ' PASS' : ' - CÓ LỖI') . " (song ngữ vi / en qua {$base}, db={$testDb})\n";
exit($fail === 0 ? 0 : 1);
