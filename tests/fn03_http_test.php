<?php

declare(strict_types=1);

/**
 * STEP 7 - Test HTTP FN-03 qua Apache XAMPP THẬT (APP_URL) trên DB của .env (energy_dss).
 *   php tests/fn03_http_test.php
 * Dữ liệu E2E dùng ngày hiệu lực năm 2098 và bị XOÁ ở cuối (audit_logs giữ lại - BR-04).
 */

require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
$runId = 'E2E' . date('His') . random_int(10, 99);
$startedAt = date('Y-m-d H:i:s');
$day = str_pad((string) random_int(1, 28), 2, '0', STR_PAD_LEFT);
$month = str_pad((string) random_int(1, 9), 2, '0', STR_PAD_LEFT);
$dTariff = "2098-{$month}-{$day}";          // phiên bản BQL tạo
$dProposal = '2098-1' . random_int(0, 2) . "-{$day}"; // đề xuất (tháng 10-12)
$dFee = "2098-{$month}-{$day}";
$dWater = "2098-{$month}-{$day}";
$jar = static fn (string $who): string => sys_get_temp_dir() . "/fn03_{$who}_{$runId}.txt";

$results = [];
function check(string $group, string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$group, $name, $ok, $detail];
}

function curlHandle(string $jar, string $method, string $url, array $post = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    return $ch;
}

function parseResponse($ch, string $raw): array
{
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

    return ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size)];
}

function http(string $jar, string $method, string $url, array $post = []): array
{
    $ch = curlHandle($jar, $method, $url, $post);
    $raw = (string) curl_exec($ch);
    $r = parseResponse($ch, $raw);
    curl_close($ch);

    return $r;
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

function submit(string $base, string $jar, string $formPath, string $postPath, array $fields): array
{
    $form = http($jar, 'GET', $base . $formPath);
    $fields['csrf_token'] = csrfFrom($form['body']);

    return http($jar, 'POST', $base . $postPath, $fields);
}

function q(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function tiers(array $rows): array
{
    $out = [];
    foreach ($rows as $i => [$from, $to, $price]) {
        $out["tiers[{$i}][tier_from]"] = $from;
        $out["tiers[{$i}][tier_to]"] = $to;
        $out["tiers[{$i}][unit_price]"] = $price;
    }

    return $out;
}

$br13 = [['0', '50', '1806'], ['50', '100', '1866'], ['100', '200', '2167'], ['200', '300', '2729'], ['300', '400', '3050'], ['400', '', '3151']];
$created = ['proposals' => [], 'tariffs' => [], 'fees' => []];

try {
    // ============================================================ BQL: biểu giá
    check('BQL', 'Đăng nhập bql01', login($base, $jar('bql'), 'bql01'));
    $page = http($jar('bql'), 'GET', $base . '/tariffs');
    check('BQL', 'Mở /tariffs (200) + sidebar nhóm "Biểu giá & phí" (mở sẵn)', $page['code'] === 200 && (bool) preg_match('#<div class="nav-group is-open" data-nav-group>\s*<button[^>]*aria-expanded="true"[^>]*>\s*<span class="nav-group__label">Biểu giá &amp; phí</span>#u', $page['body']));

    $r = submit($base, $jar('bql'), '/tariffs/create', '/tariffs', ['utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'vat_percent' => '8', 'effective_date' => $dTariff]
        + tiers([['0', '50', '1806'], ['51', '100', '1866'], ['100', '', '2000']]));
    $form = http($jar('bql'), 'GET', $base . '/tariffs/create')['body'];
    check('TARIFF', 'Bậc kiểu +1 (0–50, 51–100) -> báo "bị hở" trên form, không lưu', str_contains($form, 'bị hở') && (int) q($pdo, 'SELECT COUNT(*) FROM tariffs WHERE effective_date = ?', [$dTariff]) === 0);

    $r = submit($base, $jar('bql'), '/tariffs/create?from=1', '/tariffs?from=1', ['utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'vat_percent' => '8', 'effective_date' => $dTariff] + tiers($br13));
    $tariffId = preg_match('#/tariffs/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $created['tariffs'][] = $tariffId;
    check('TARIFF', "Tạo phiên bản điện sinh hoạt BR-13 ({$dTariff}) qua form", $r['code'] === 302 && $tariffId > 0);
    $amount = (float) q($pdo, 'SELECT SUM(GREATEST(0, LEAST(343, COALESCE(tier_to, 343)) - tier_from) * unit_price) FROM tariff_tiers WHERE tariff_id = ?', [$tariffId]);
    check('REGRESSION', '343 kWh trên bậc vừa tạo qua web = 804.350; VAT 8% = 64.348; tổng 868.698', $amount == 804350.0 && round($amount * 0.08) == 64348.0 && $amount + round($amount * 0.08) == 868698.0, (string) $amount);
    $show = http($jar('bql'), 'GET', $base . '/tariffs/' . $tariffId)['body'];
    check('TARIFF', 'Chi tiết: hiện phiên bản, Sắp áp dụng, bảng bậc nửa khoảng, phiên bản cũ giữ nguyên', str_contains($show, 'Sắp áp dụng') && str_contains($show, '[0, 50)') && str_contains($show, '[400, ∞)')
        && q($pdo, 'SELECT unit_price FROM tariff_tiers WHERE tariff_id = 1 AND tier_order = 1') === '1806.00');

    // ============================================================ BQL: phí cố định
    $r = submit($base, $jar('bql'), '/fees/create?fee_type=gui_xe_may', '/fees', ['fee_type' => 'gui_xe_may', 'unit_price' => '0', 'effective_date' => $dFee]);
    check('FIXED FEE', 'Đơn giá 0 -> lỗi "Đơn giá phải lớn hơn 0."', str_contains(http($jar('bql'), 'GET', $base . '/fees/create?fee_type=gui_xe_may')['body'], 'Đơn giá phải lớn hơn 0.'));
    $r = submit($base, $jar('bql'), '/fees/create?fee_type=gui_xe_may', '/fees', ['fee_type' => 'gui_xe_may', 'unit_price' => '60000', 'effective_date' => $dFee]);
    $feeId = (int) q($pdo, "SELECT id FROM fixed_fee_rates WHERE fee_type = 'gui_xe_may' AND effective_date = ?", [$dFee]);
    $created['fees'][] = $feeId;
    check('FIXED FEE', 'Tạo đơn giá gửi xe máy 60.000 (Sắp áp dụng), đơn giá hiện hành giữ nguyên', $r['code'] === 302 && $feeId > 0
        && str_contains(http($jar('bql'), 'GET', $base . '/fees?fee_type=gui_xe_may')['body'], 'Sắp áp dụng'));
    $edit = http($jar('bql'), 'GET', $base . "/fees/{$feeId}/edit")['body'];
    preg_match('/name="version_token" value="([^"]+)"/', $edit, $tok);
    $r = http($jar('bql'), 'POST', $base . "/fees/{$feeId}/update", ['csrf_token' => csrfFrom($edit), 'unit_price' => '65000', 'effective_date' => $dFee, 'version_token' => $tok[1] ?? '']);
    check('FIXED FEE', 'Sửa đơn giá Sắp áp dụng -> 65.000', q($pdo, 'SELECT unit_price FROM fixed_fee_rates WHERE id = ?', [$feeId]) === '65000.00');

    // ============================================================ KẾ TOÁN: đề xuất
    check('PROPOSAL', 'Đăng nhập ketoan01', login($base, $jar('kto'), 'ketoan01'));
    check('RBAC', 'Kế toán GET /tariffs/create -> 403', http($jar('kto'), 'GET', $base . '/tariffs/create')['code'] === 403);
    check('RBAC', 'Kế toán GET /fees/create -> 403', http($jar('kto'), 'GET', $base . '/fees/create')['code'] === 403);
    $r = submit($base, $jar('kto'), "/tariff-proposals/create?tariff_id={$tariffId}", '/tariff-proposals',
        ['tariff_id' => (string) $tariffId, 'vat_percent' => '8', 'effective_date' => $dProposal, 'reason' => "E2E {$runId}"] + tiers([['0', '50', '1900'], ['50', '100', '1950'], ['100', '', '2300']]));
    $proposalId = preg_match('#/tariff-proposals/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $created['proposals'][] = $proposalId;
    check('PROPOSAL', 'Kế toán tạo đề xuất -> Chờ duyệt, chưa tạo biểu giá', $proposalId > 0 && q($pdo, 'SELECT status FROM tariff_proposals WHERE id = ?', [$proposalId]) === 'cho_duyet'
        && (int) q($pdo, 'SELECT COUNT(*) FROM tariffs WHERE effective_date = ?', [$dProposal]) === 0);
    $r = submit($base, $jar('kto'), "/tariff-proposals/create?tariff_id={$tariffId}", '/tariff-proposals',
        ['tariff_id' => (string) $tariffId, 'vat_percent' => '8', 'effective_date' => '2098-12-31', 'reason' => 'dup'] + tiers($br13));
    check('PROPOSAL', 'Duplicate pending -> từ chối, thông báo VAL-03.05', str_contains(http($jar('kto'), 'GET', $base . "/tariff-proposals/create?tariff_id={$tariffId}")['body'], 'Biểu giá này đang có đề xuất chờ duyệt.'));
    $csrfKto = csrfFrom(http($jar('kto'), 'GET', $base . '/tariff-proposals')['body']);
    check('RBAC', 'Kế toán POST approve -> 403 (unauthorized approve)', http($jar('kto'), 'POST', $base . "/tariff-proposals/{$proposalId}/approve", ['csrf_token' => $csrfKto])['code'] === 403
        && q($pdo, 'SELECT status FROM tariff_proposals WHERE id = ?', [$proposalId]) === 'cho_duyet');

    // ============================================================ KỸ THUẬT: trái quyền
    check('RBAC', 'Đăng nhập kythuat01', login($base, $jar('kt'), 'kythuat01'));
    foreach (['/tariffs', "/tariffs/{$tariffId}", '/fees', '/tariff-proposals', "/tariff-proposals/{$proposalId}"] as $path) {
        check('RBAC', "Kỹ thuật GET {$path} -> 403 (J-06)", http($jar('kt'), 'GET', $base . $path)['code'] === 403);
    }
    $csrfKt = csrfFrom(http($jar('kt'), 'GET', $base . '/dashboard')['body']);
    check('RBAC', 'Kỹ thuật POST approve -> 403', http($jar('kt'), 'POST', $base . "/tariff-proposals/{$proposalId}/approve", ['csrf_token' => $csrfKt])['code'] === 403);
    check('RBAC', 'Kỹ thuật: sidebar không có nhóm Biểu giá & phí', !str_contains($ktDash = http($jar('kt'), 'GET', $base . '/dashboard')['body'], 'Biểu giá &amp; phí') && !str_contains($ktDash, '/tariffs"'));
    check('RBAC', 'Chỉ 3 vai trò: admin01 không đăng nhập được (J-01 đã chốt)', !login($base, $jar('adm'), 'admin01'));

    // ============================================================ BQL: CSRF + duyệt đồng thời
    $r = http($jar('bql'), 'POST', $base . "/tariff-proposals/{$proposalId}/approve", ['csrf_token' => 'invalid-token']);
    check('CSRF', 'BQL approve với CSRF sai -> 403, đề xuất vẫn Chờ duyệt', $r['code'] === 403 && q($pdo, 'SELECT status FROM tariff_proposals WHERE id = ?', [$proposalId]) === 'cho_duyet');

    check('CONCURRENCY', 'Đăng nhập phiên BQL thứ 2', login($base, $jar('bql2'), 'bql01'));
    $show = http($jar('bql'), 'GET', $base . "/tariff-proposals/{$proposalId}")['body'];
    check('PROPOSAL', 'BQL xem chi tiết: so sánh hiện hành ↔ đề xuất, có nút Duyệt + modal Từ chối', str_contains($show, 'So sánh hiện hành') && str_contains($show, 'id="rejectModal"') && str_contains($show, '>Duyệt</button>'));
    $tokens = [csrfFrom($show), csrfFrom(http($jar('bql2'), 'GET', $base . "/tariff-proposals/{$proposalId}")['body'])];
    $mh = curl_multi_init();
    $handles = [];
    foreach (['bql', 'bql2'] as $i => $who) {
        $handles[$i] = curlHandle($jar($who), 'POST', $base . "/tariff-proposals/{$proposalId}/approve", ['csrf_token' => $tokens[$i]]);
        curl_multi_add_handle($mh, $handles[$i]);
    }
    do {
        $status = curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0 && $status === CURLM_OK);
    foreach ($handles as $h) {
        curl_multi_remove_handle($mh, $h);
    }
    curl_multi_close($mh);
    $flashes = [http($jar('bql'), 'GET', $base . "/tariff-proposals/{$proposalId}")['body'], http($jar('bql2'), 'GET', $base . "/tariff-proposals/{$proposalId}")['body']];
    $success = count(array_filter($flashes, static fn ($b) => str_contains($b, 'Đã duyệt đề xuất')));
    $rejected = count(array_filter($flashes, static fn ($b) => str_contains($b, 'Đề xuất đã được xử lý, vui lòng tải lại.')));
    $newTariffs = (int) q($pdo, "SELECT COUNT(*) FROM tariffs WHERE utility_type='dien' AND usage_type='sinh_hoat' AND effective_date = ?", [$dProposal]);
    $created['tariffs'][] = (int) q($pdo, "SELECT MAX(id) FROM tariffs WHERE utility_type='dien' AND usage_type='sinh_hoat' AND effective_date = ?", [$dProposal]);
    check('CONCURRENCY', '2 BQL duyệt cùng lúc qua Apache: 1 thành công, 1 "đã được xử lý", chỉ 1 phiên bản mới', $success === 1 && $rejected === 1 && $newTariffs === 1, "success={$success} rejected={$rejected} tariffs={$newTariffs}");
    check('PROPOSAL', 'Sau duyệt: đề xuất Đã duyệt, phiên bản mới created_by=Kế toán approved_by=BQL', q($pdo, 'SELECT status FROM tariff_proposals WHERE id = ?', [$proposalId]) === 'da_duyet'
        && q($pdo, "SELECT CONCAT(created_by, '|', approved_by) FROM tariffs WHERE utility_type='dien' AND usage_type='sinh_hoat' AND effective_date = ?", [$dProposal]) === '3|1');

    // ============================================================ Từ chối
    $waterBase = (int) q($pdo, "SELECT id FROM tariffs WHERE utility_type='nuoc' AND usage_type='kinh_doanh' ORDER BY effective_date DESC, id DESC LIMIT 1");
    $r = submit($base, $jar('kto'), "/tariff-proposals/create?tariff_id={$waterBase}", '/tariff-proposals',
        ['tariff_id' => (string) $waterBase, 'vat_percent' => '', 'business_unit_price' => '26500', 'effective_date' => $dWater, 'reason' => "E2E {$runId}"]);
    $rejectId = preg_match('#/tariff-proposals/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
    $created['proposals'][] = $rejectId;
    $show = http($jar('bql'), 'GET', $base . "/tariff-proposals/{$rejectId}")['body'];
    http($jar('bql'), 'POST', $base . "/tariff-proposals/{$rejectId}/reject", ['csrf_token' => csrfFrom($show), 'rejection_reason' => '']);
    $show = http($jar('bql'), 'GET', $base . "/tariff-proposals/{$rejectId}")['body'];
    check('PROPOSAL', 'Reject thiếu lý do -> lỗi, modal mở lại, vẫn Chờ duyệt', str_contains($show, 'Vui lòng nhập lý do từ chối.') && !str_contains($show, 'id="rejectModal" role="dialog" aria-modal="true" aria-labelledby="rejectModalTitle" hidden')
        && q($pdo, 'SELECT status FROM tariff_proposals WHERE id = ?', [$rejectId]) === 'cho_duyet');
    http($jar('bql'), 'POST', $base . "/tariff-proposals/{$rejectId}/reject", ['csrf_token' => csrfFrom($show), 'rejection_reason' => 'Thiếu căn cứ <script>x</script>']);
    $show = http($jar('bql'), 'GET', $base . "/tariff-proposals/{$rejectId}")['body'];
    check('PROPOSAL', 'Reject có lý do -> Từ chối, lý do hiển thị đã escape, biểu giá nước giữ nguyên', q($pdo, 'SELECT status FROM tariff_proposals WHERE id = ?', [$rejectId]) === 'tu_choi'
        && str_contains($show, 'Thiếu căn cứ &lt;script&gt;') && (int) q($pdo, 'SELECT COUNT(*) FROM tariffs WHERE effective_date = ?', [$dWater]) <= 1);

    // ============================================================ AUDIT + app.log
    $events = $pdo->prepare("SELECT action, COUNT(*) FROM audit_logs WHERE created_at >= ? AND action IN
        ('CREATE_TARIFF','UPDATE_TARIFF','CREATE_TARIFF_PROPOSAL','APPROVE_TARIFF_PROPOSAL','REJECT_TARIFF_PROPOSAL','CREATE_FIXED_FEE','UPDATE_FIXED_FEE') GROUP BY action");
    $events->execute([$startedAt]);
    $seen = $events->fetchAll(PDO::FETCH_KEY_PAIR);
    $need = ['CREATE_TARIFF' => 2, 'CREATE_TARIFF_PROPOSAL' => 2, 'APPROVE_TARIFF_PROPOSAL' => 1, 'REJECT_TARIFF_PROPOSAL' => 1, 'CREATE_FIXED_FEE' => 1, 'UPDATE_FIXED_FEE' => 1];
    $okAudit = true;
    foreach ($need as $action => $n) {
        $okAudit = $okAudit && (int) ($seen[$action] ?? 0) === $n;
    }
    check('AUDIT', 'Audit đủ: CREATE_TARIFF×2, CREATE/APPROVE/REJECT proposal, CREATE/UPDATE fixed fee', $okAudit, json_encode($seen));
    $denied = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'access_denied' AND created_at >= ?");
    $denied->execute([$startedAt]);
    check('AUDIT', 'Truy cập trái quyền được ghi access_denied', (int) $denied->fetchColumn() >= 9); // 10 trước đây gồm 1 lượt Admin (đã bỏ)

    $logFile = BASE_PATH . '/storage/logs/app.log';
    clearstatcache();
    $before = is_file($logFile) ? filesize($logFile) : 0;
    http($jar('anon'), 'GET', $base . '/login');
    http($jar('anon'), 'POST', $base . '/login', ['csrf_token' => 'log-check-' . $runId, 'username' => 'x', 'password' => 'x']);
    clearstatcache();
    $tail = (string) file_get_contents($logFile, false, null, $before);
    check('LOGGING', 'Request web có CSRF sai -> app.log có dòng [SECURITY] mới (Apache ghi được)', filesize($logFile) > $before && str_contains($tail, '[SECURITY] CSRF token rejected: POST ' . parse_url($base, PHP_URL_PATH) . '/login')); // đường dẫn theo base (Apache / php -S)
} finally {
    foreach (array_filter($created['proposals']) as $id) {
        $pdo->prepare('DELETE FROM tariff_proposals WHERE id = ?')->execute([$id]);
    }
    foreach (array_filter($created['tariffs']) as $id) {
        $pdo->prepare('DELETE FROM tariffs WHERE id = ?')->execute([$id]); // tariff_tiers xoá theo CASCADE
    }
    foreach (array_filter($created['fees']) as $id) {
        $pdo->prepare('DELETE FROM fixed_fee_rates WHERE id = ?')->execute([$id]);
    }
    // chỉ đếm bản ghi thuộc lượt chạy: id đã ghi nhận + đúng các ngày hiệu lực ngẫu nhiên của lượt chạy - không đếm biểu giá / phí của người dùng
    $dates = [$dTariff, $dProposal, $dFee, $dWater, '2098-12-31'];
    $in = static fn (array $ids): string => implode(',', array_map('intval', array_filter($ids))) ?: '0';
    $dp = implode(',', array_fill(0, count($dates), '?'));
    $left = (int) q($pdo, "SELECT (SELECT COUNT(*) FROM tariffs WHERE id IN ({$in($created['tariffs'])}) OR effective_date IN ({$dp}))
        + (SELECT COUNT(*) FROM fixed_fee_rates WHERE id IN ({$in($created['fees'])}) OR effective_date IN ({$dp}))
        + (SELECT COUNT(*) FROM tariff_proposals WHERE id IN ({$in($created['proposals'])}) OR effective_date IN ({$dp}))", array_merge($dates, $dates, $dates));
    check('CLEANUP', 'Đã xóa dữ liệu E2E (ngày hiệu lực 2098 của lượt chạy) khỏi DB thật, audit giữ lại', $left === 0, "còn {$left}");
    foreach (['bql', 'bql2', 'kto', 'kt', 'adm', 'anon'] as $who) {
        @unlink($jar($who));
    }
}

$failed = 0;
foreach ($results as [$group, $name, $ok, $detail]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-11s %s%s\n", $ok ? 'PASS' : 'FAIL', $group, $name, $ok || $detail === '' ? '' : "  -> {$detail}");
}
printf("\n%d/%d PASS (HTTP test qua %s, run %s)\n", count($results) - $failed, count($results), $base, $runId);
exit($failed === 0 ? 0 : 1);
