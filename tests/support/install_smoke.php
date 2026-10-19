<?php

declare(strict_types=1);

/**
 * STEP 14 - smoke test HTTP sau cài mới / nâng cấp:  php tests/support/install_smoke.php <base_url> <db_tạm>
 * Đăng nhập 4 role, mở các màn hình chính, chạy luồng tối thiểu: tạo kỳ -> nhập chỉ số -> duyệt -> tính phí.
 */

[$_, $base, $db] = $argv + [null, '', ''];
if ($db === '' || $db === 'energy_dss') {
    fwrite(STDERR, "Cần DB tạm.\n");
    exit(2);
}
$pdo = new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$results = [];
$check = static function (string $n, bool $ok, string $d = '') use (&$results): void {
    $results[] = [$n, $ok, $d];
};
$jar = static fn (string $w): string => sys_get_temp_dir() . "/smoke_{$w}_" . getmypid() . '.txt';
$http = static function (string $jar, string $method, string $url, $post = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
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
};
$csrf = static function (string $html): string {
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
};
foreach (['bql' => 'bql01', 'kt' => 'kythuat01', 'kto' => 'ketoan01'] as $w => $u) {
    @unlink($jar($w));
    $page = $http($jar($w), 'GET', "{$base}/login");
    $r = $http($jar($w), 'POST', "{$base}/login", ['csrf_token' => $csrf($page['body']), 'username' => $u, 'password' => 'Password@123']);
    $check("đăng nhập {$u}", $r['code'] === 302 && str_ends_with($r['location'], '/dashboard'));
}
$fails = [];
foreach (['/dashboard', '/buildings', '/zones', '/apartments', '/meters', '/tariffs', '/fees', '/tariff-proposals', '/periods', '/readings', '/periods/2/billing',
    '/periods/2/cost-allocation', '/baselines', '/comparisons', '/targets', '/targets/tracking', '/alert-rules', '/alerts', '/recommendation-rules', '/reports/utility', '/charges/1', '/charges/1/notice'] as $p) {
    $r = $http($jar('bql'), 'GET', $base . $p);
    if ($r['code'] !== 200 || preg_match('/Fatal|Uncaught|Warning:|Notice:/', $r['body'])) {
        $fails[] = "{$p}={$r['code']}";
    }
}
$check('BQL mở 22 màn hình chính → 200, không lỗi PHP', $fails === [], implode(', ', $fails));
$form = $http($jar('bql'), 'GET', "{$base}/periods/create");
$r = $http($jar('bql'), 'POST', "{$base}/periods", ['csrf_token' => $csrf($form['body']), 'month' => '1', 'year' => '2091']);
$pid = preg_match('#/periods/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
$check('BQL tạo kỳ 01/2091', $pid > 0);
$page = $http($jar('kt'), 'GET', "{$base}/periods/{$pid}/readings/create?meter_id=1");
$http($jar('kt'), 'POST', "{$base}/periods/{$pid}/readings", ['csrf_token' => $csrf($page['body']), 'meter_id' => '1', 'current_reading' => '1686']);
$page = $http($jar('kt'), 'GET', "{$base}/periods/{$pid}/readings/create?meter_id=2");
$http($jar('kt'), 'POST', "{$base}/periods/{$pid}/readings", ['csrf_token' => $csrf($page['body']), 'meter_id' => '2', 'current_reading' => '232']); // BT1 có 2 công tơ (BR-11)
$rid = (int) $pdo->query("SELECT id FROM meter_readings WHERE billing_period_id = {$pid} AND meter_id = 1")->fetchColumn();
$rid2 = (int) $pdo->query("SELECT id FROM meter_readings WHERE billing_period_id = {$pid} AND meter_id = 2")->fetchColumn();
$check('KT nhập chỉ số BT1-DIEN (CL 343 do server tính)', $rid > 0 && $pdo->query("SELECT consumption FROM meter_readings WHERE id = {$rid}")->fetchColumn() === '343.00');
$ap = $http($jar('bql'), 'GET', "{$base}/periods/{$pid}/approvals");
preg_match('/name="token_' . $rid . '" value="([0-9a-f]{40})"/', $ap['body'], $t);
preg_match('/name="token_' . $rid2 . '" value="([0-9a-f]{40})"/', $ap['body'], $t2);
$http($jar('bql'), 'POST', "{$base}/periods/{$pid}/approvals", 'csrf_token=' . urlencode($csrf($ap['body'])) . "&reading_ids%5B%5D={$rid}&token_{$rid}=" . ($t[1] ?? '') . "&reading_ids%5B%5D={$rid2}&token_{$rid2}=" . ($t2[1] ?? ''));
$check('BQL duyệt 2 chỉ số', (int) $pdo->query("SELECT COUNT(*) FROM meter_readings WHERE id IN ({$rid}, {$rid2}) AND status = 'da_xac_nhan'")->fetchColumn() === 2);
$bl = $http($jar('kto'), 'GET', "{$base}/periods/{$pid}/billing");
$http($jar('kto'), 'POST', "{$base}/periods/{$pid}/billing/calculate", ['csrf_token' => $csrf($bl['body']), 'apartment_id' => '1']);
$check('KTo tính phí: BT1 điện 804.350 + VAT 64.348, nước 185.120, tổng phát sinh 1.950.058', $pdo->query("SELECT CONCAT(electricity_amount, '|', electricity_vat_amount, '|', water_amount, '|', total_arising) FROM apartment_charges WHERE billing_period_id = {$pid} AND apartment_id = 1")->fetchColumn() === '804350.00|64348.00|185120.00|1950058.00');
foreach (['bql', 'kt', 'kto', 'adm'] as $w) {
    @unlink($jar($w));
}
$failed = 0;
foreach ($results as [$n, $ok, $d]) {
    $failed += $ok ? 0 : 1;
    echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($ok || $d === '' ? '' : " -> {$d}") . "\n";
}
printf("%d/%d PASS (smoke %s, db=%s)\n", count($results) - $failed, count($results), $base, $db);
exit($failed === 0 ? 0 : 1);
