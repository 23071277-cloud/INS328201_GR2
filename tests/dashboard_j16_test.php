<?php

declare(strict_types=1);

/**
 * BUGFIX - Dashboard 500 sau J-16 (utility_type NULL của cảnh báo "Điện + Nước"). DB TẠM + php -S (tests/run_dashboard_j16_tests.sh).
 *   php tests/dashboard_j16_test.php <base_url> <db_tạm>
 * Ca: không có cảnh báo; ngưỡng điện; ngưỡng nước; J-16 chỉ điện; J-16 chỉ nước; J-16 cả hai (NULL); J-16 thiếu trường tuỳ chọn
 * (snapshot / actual / percent / mô tả NULL). Mọi vai trò mở dashboard: 200, không lỗi PHP trong app.log.
 */

[$_, $base, $testDb] = $argv + [null, '', ''];
if ($base === '' || $testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Dùng: php tests/dashboard_j16_test.php <base_url> <db_tạm>\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_ENV['DB_DATABASE'] = $testDb;
App\Core\Config::set('database.database', $testDb);
$pdo = App\Core\Database::connection();
$base = rtrim($base, '/');
$jar = static fn (string $who): string => sys_get_temp_dir() . "/dj16_{$who}_" . getmypid() . '.txt';
$logFile = BASE_PATH . '/storage/logs/app.log';

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

function newLog(string $file, int $from): string
{
    clearstatcache();

    return is_file($file) ? (string) file_get_contents($file, false, null, $from) : '';
}

/** Bảng "Cảnh báo cần xử lý" của dashboard: [alert_id => nhãn tiện ích]. */
function alertRows(string $html): array
{
    preg_match_all('#/alerts/(\d+)">\#\d+</a></td><td>.*?</td>\s*<td>.*?</td><td>([^<]*)</td>#s', $html, $m, PREG_SET_ORDER);

    return array_column(array_map(static fn ($x) => [(int) $x[1], html_entity_decode($x[2])], $m), 1, 0);
}

$insert = static function (array $row) use ($pdo): int {
    $row += ['alert_type' => 'threshold', 'alert_rule_id' => null, 'meter_id' => null, 'apartment_id' => 1, 'billing_period_id' => 2, 'utility_type' => 'dien',
        'source' => 'thu_cong', 'priority' => 'trung_binh', 'status' => 'moi', 'description' => 'x', 'snapshot_json' => null, 'actual_value' => null, 'percent_change' => null];
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO alerts (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')->execute($row);

    return (int) $pdo->lastInsertId();
};

try {
    foreach (['bql' => 'bql01', 'kto' => 'ketoan01', 'kt' => 'kythuat01'] as $who => $user) {
        check('SETUP', "Đăng nhập {$user}", login($base, $jar($who), $user));
    }
    $from = is_file($logFile) ? (int) filesize($logFile) : 0;

    $G = 'NO ALERT';
    $d = http($jar('bql'), 'GET', $base . '/dashboard?period_id=2');
    check($G, 'không có cảnh báo → 200, "Không có cảnh báo đang mở"', $d['code'] === 200 && str_contains($d['body'], 'Không có cảnh báo đang mở.'));

    $G = 'ALERTS';
    $ids = [
        'thr_dien' => $insert(['alert_rule_id' => 1, 'meter_id' => 1, 'utility_type' => 'dien', 'source' => 'rule', 'actual_value' => '343', 'percent_change' => '14.33', 'priority' => 'cao']),
        'thr_nuoc' => $insert(['alert_rule_id' => 2, 'meter_id' => 2, 'apartment_id' => 1, 'utility_type' => 'nuoc', 'source' => 'rule', 'actual_value' => '16', 'percent_change' => '-5.00']),
        'j16_dien' => $insert(['alert_type' => 'no_usage_consumption', 'apartment_id' => 4, 'utility_type' => 'dien', 'source' => 'he_thong', 'actual_value' => '25', 'snapshot_json' => '{"electricity_consumption":"25.00","water_consumption":"0.00"}']),
        'j16_nuoc' => $insert(['alert_type' => 'no_usage_consumption', 'apartment_id' => 3, 'utility_type' => 'nuoc', 'source' => 'he_thong', 'actual_value' => '2', 'snapshot_json' => '{}']),
        // cả điện + nước: utility_type NULL; thiếu trường tuỳ chọn (actual / percent / snapshot / mô tả NULL, không rule, không công tơ)
        'j16_both' => $insert(['alert_type' => 'no_usage_consumption', 'apartment_id' => 2, 'utility_type' => null, 'source' => 'he_thong', 'description' => null, 'priority' => 'thap']),
    ];
    foreach (['bql' => 'BQL', 'kto' => 'Kế toán', 'kt' => 'Kỹ thuật'] as $who => $label) {
        $d = http($jar($who), 'GET', $base . '/dashboard?period_id=2');
        check($G, "{$label} mở dashboard (kỳ có 5 cảnh báo, gồm J-16 NULL) → 200, không lỗi", $d['code'] === 200 && !preg_match('/Fatal|Undefined array key|Warning:/', $d['body']), 'HTTP ' . $d['code']);
    }
    $d = http($jar('bql'), 'GET', $base . '/dashboard?period_id=2');
    $rows = alertRows($d['body']);
    check($G, 'ngưỡng điện → "Điện"', ($rows[$ids['thr_dien']] ?? '') === 'Điện', json_encode($rows, JSON_UNESCAPED_UNICODE));
    check($G, 'ngưỡng nước → "Nước"', ($rows[$ids['thr_nuoc']] ?? '') === 'Nước');
    check($G, 'J-16 chỉ điện → "Điện"', ($rows[$ids['j16_dien']] ?? '') === 'Điện');
    check($G, 'J-16 chỉ nước → "Nước"', ($rows[$ids['j16_nuoc']] ?? '') === 'Nước');
    check($G, 'J-16 cả hai (utility_type NULL, thiếu actual / percent / snapshot / mô tả) → "Điện + Nước", % = "—"', ($rows[$ids['j16_both']] ?? '') === 'Điện + Nước'
        && (bool) preg_match('#/alerts/' . $ids['j16_both'] . '">.*?Điện \+ Nước</td>\s*<td class="num">—</td>#s', $d['body']));
    check($G, 'tổng hợp "đang mở theo tiện ích": Điện 2, Nước 2, Điện + Nước 1 (không có khoá rỗng)', str_contains($d['body'], 'đang mở theo tiện ích: Điện 2 &nbsp;Nước 2 &nbsp;Điện + Nước 1 &nbsp;')
        || (str_contains($d['body'], 'Điện 2 &nbsp;') && str_contains($d['body'], 'Nước 2 &nbsp;') && str_contains($d['body'], 'Điện + Nước 1 &nbsp;')));
    check($G, 'chỉ báo J-16 trên dashboard: 3 cảnh báo trạng thái căn / tiêu thụ không khớp', (bool) preg_match('/' . preg_quote(e(config('j16.dashboard_label')), '/') . ': <strong>3<\/strong>/u', $d['body']));
    check($G, 'trang danh sách / chi tiết cảnh báo J-16 NULL (thiếu snapshot) cũng không 500', http($jar('bql'), 'GET', $base . '/alerts?period_id=2')['code'] === 200
        && http($jar('bql'), 'GET', $base . '/alerts/' . $ids['j16_both'])['code'] === 200);

    $G = 'HELPER';
    check($G, 'alert_utility_key: FN-07 NULL / thiếu khoá / giá trị lạ → unknown (không suy ra "Điện + Nước")', alert_utility_key(['alert_type' => 'threshold', 'utility_type' => null]) === 'unknown'
        && alert_utility_key([]) === 'unknown' && alert_utility_key(['utility_type' => 'gas']) === 'unknown' && alert_utility_text([]) === 'Không xác định'
        && alert_utility_key(['alert_type' => 'no_usage_consumption', 'utility_type' => null]) === 'both');

    $G = 'LOG';
    $log = newLog($logFile, $from);
    check($G, 'app.log: không có PHP Error / Undefined array key / Uncaught trong lúc test', !preg_match('/PHP Error|Undefined array key|Uncaught|Fatal/i', $log), mb_substr($log, 0, 300));
} finally {
    foreach (['bql', 'kto', 'kt'] as $who) {
        @unlink($jar($who));
    }
}

$pass = 0;
$current = '';
foreach ($results as [$g, $n, $ok, $det]) {
    if ($g !== $current) {
        echo "\n[{$g}]\n";
        $current = $g;
    }
    echo ($ok ? '  PASS ' : '  FAIL ') . $n . ($ok || $det === '' ? '' : "  -> {$det}") . "\n";
    $pass += $ok ? 1 : 0;
}
$total = count($results);
echo "\n{$pass}/{$total} PASS (dashboard J-16 qua {$base}, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
