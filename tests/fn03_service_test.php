<?php

declare(strict_types=1);

/**
 * STEP 7 - Test cấp Service cho FN-03 (biểu giá, bậc, phiên bản, đề xuất, duyệt đồng thời, phí cố định).
 * Chạy trên DATABASE TẠM:  php tests/fn03_service_test.php energy_dss_fn03_test
 * (tests/run_fn03_tests.sh tự tạo DB tạm từ database/energy_dss_v2.sql rồi xoá.)
 */

$testDb = $argv[1] ?? '';
if ($testDb === '' || $testDb === 'energy_dss') {
    fwrite(STDERR, "Cần tên database TẠM (khác energy_dss).\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';
$_ENV['DB_DATABASE'] = $testDb;
restore_error_handler();
restore_exception_handler();

use App\Core\Database;
use App\Core\ValidationException;
use App\Services\FixedFeeService;
use App\Services\TariffProposalService;
use App\Services\TariffService;

require_once __DIR__ . '/support/legacy_compat.php'; // STEP 4C: chạy nguyên văn trên schema v1 hoặc v2
$pdo = legacy_compat_install();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SERVER['REMOTE_ADDR'] = 'fn03-service-test';

function actAs(int $userId, string $role): void
{
    $_SESSION = ['user_id' => $userId, 'user_role' => $role, 'username' => $role, 'full_name' => $role];
}

$results = [];
function check(string $group, string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$group, $name, $ok, $detail];
}

function expectError(string $group, string $name, string $field, callable $fn, string $contains = ''): void
{
    try {
        $fn();
        check($group, $name, false, 'không có lỗi');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        $messages = json_encode($errors, JSON_UNESCAPED_UNICODE);
        $ok = isset($errors[$field]) && ($contains === '' || str_contains($messages, $contains));
        check($group, $name, $ok, $ok ? '' : $messages);
    }
}

function expectOk(string $group, string $name, callable $fn)
{
    try {
        $r = $fn();
        check($group, $name, true);

        return $r;
    } catch (ValidationException $e) {
        check($group, $name, false, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

        return null;
    }
}

function q(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function auditCount(PDO $pdo, string $action, string $entity, int $id): int
{
    return (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_type = ? AND entity_id = ?', [$action, $entity, (string) $id]);
}

/** Thuật toán tham chiếu (cùng quy ước schema A1): qty = max(0, min(CL, to ?? CL) - from), KHÔNG +1. */
function tierAmount(PDO $pdo, int $tariffId, float $cl): float
{
    $stmt = $pdo->prepare('SELECT tier_from, tier_to, unit_price FROM tariff_tiers WHERE tariff_id = ? ORDER BY tier_order');
    $stmt->execute([$tariffId]);
    $sum = 0.0;
    foreach ($stmt as $t) {
        $to = $t['tier_to'] === null ? $cl : (float) $t['tier_to'];
        $sum += max(0.0, min($cl, $to) - (float) $t['tier_from']) * (float) $t['unit_price'];
    }

    return round($sum, 2);
}

/** Bậc điện BR-13 dạng form (nhập tay như người dùng). */
function br13Tiers(): array
{
    return [
        ['tier_from' => '0', 'tier_to' => '50', 'unit_price' => '1806'],
        ['tier_from' => '50', 'tier_to' => '100', 'unit_price' => '1866'],
        ['tier_from' => '100', 'tier_to' => '200', 'unit_price' => '2167'],
        ['tier_from' => '200', 'tier_to' => '300', 'unit_price' => '2729'],
        ['tier_from' => '300', 'tier_to' => '400', 'unit_price' => '3050'],
        ['tier_from' => '400', 'tier_to' => '', 'unit_price' => '3151'],
    ];
}

$tariffs = new TariffService();
$proposals = new TariffProposalService();
$fees = new FixedFeeService();
$base = ['utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'vat_percent' => '8', 'business_unit_price' => '', 'effective_date' => '2027-01-01'];

// ============================================================ TARIFF (FN-03.01)
$G = 'TARIFF';
actAs(1, 'bql');
$seedTier = $pdo->query('SELECT tariff_id, tier_order, tier_from, tier_to, unit_price FROM tariff_tiers WHERE tariff_id = 1 ORDER BY tier_order')->fetchAll();
$seedRow = $pdo->query('SELECT * FROM tariffs WHERE id = 1')->fetch();

$t2 = expectOk($G, 'create residential tiered (điện sinh hoạt 6 bậc BR-13, 2027-01-01)', fn () => $tariffs->create($base + ['tiers' => br13Tiers()]));
check($G, 'bậc lưu đúng nửa khoảng [0,50),[50,100)…[400,∞), không +1',
    $pdo->query("SELECT GROUP_CONCAT(CONCAT(tier_from+0,'-',IFNULL(tier_to+0,'x')) ORDER BY tier_order) FROM tariff_tiers WHERE tariff_id = {$t2}")->fetchColumn() === '0.00-50.00,50.00-100.00,100.00-200.00,200.00-300.00,300.00-400.00,400.00-x');
check($G, 'create: status hieu_luc, created_by = approved_by = BQL, audit CREATE_TARIFF', q($pdo, 'SELECT CONCAT(status, created_by, approved_by) FROM tariffs WHERE id = ?', [$t2]) === 'hieu_luc11'
    && auditCount($pdo, 'CREATE_TARIFF', 'tariff', (int) $t2) === 1);
check($G, 'phiên bản mới là v2, vòng đời Sắp áp dụng', (int) $tariffs->find((int) $t2)['version_no'] === 2 && $tariffs->find((int) $t2)['lifecycle'] === 'sap_ap_dung');

// Regression ví dụ tài liệu (FR-05 Acceptance, phiếu BT1 06/2024)
foreach ([1 => 'seed', (int) $t2 => 'phiên bản vừa tạo'] as $tid => $label) {
    $amount = tierAmount($pdo, $tid, 343);
    $vat = round($amount * (float) q($pdo, 'SELECT vat_percent FROM tariffs WHERE id = ?', [$tid]) / 100);
    check('REGRESSION', "343 kWh trên bậc {$label}: 804.350 + VAT 64.348 = 868.698", $amount == 804350.0 && $vat == 64348.0 && $amount + $vat == 868698.0, "{$amount} + {$vat}");
}

$tKd = expectOk($G, 'create business flat (điện kinh doanh 4.000 đ)', fn () => $tariffs->create(['utility_type' => 'dien', 'usage_type' => 'kinh_doanh', 'vat_percent' => '8', 'business_unit_price' => '4000', 'effective_date' => '2027-01-01', 'tiers' => br13Tiers()]));
check($G, 'kinh doanh: business_unit_price = 4000.00, không có bậc', q($pdo, 'SELECT business_unit_price FROM tariffs WHERE id = ?', [$tKd]) === '4000.00'
    && (int) q($pdo, 'SELECT COUNT(*) FROM tariff_tiers WHERE tariff_id = ?', [$tKd]) === 0);

$mk = fn (array $tiers, array $over = []) => fn () => $tariffs->create(array_merge($base, ['effective_date' => '2027-02-01'], $over, ['tiers' => $tiers]));
$tiers = br13Tiers(); $tiers[2]['unit_price'] = '0';
expectError($G, 'unit_price = 0 ở bậc 3', 'tiers.2', $mk($tiers), 'đơn giá phải lớn hơn 0');
expectError($G, 'business unit_price = 0', 'business_unit_price', fn () => $tariffs->create(['utility_type' => 'nuoc', 'usage_type' => 'kinh_doanh', 'vat_percent' => '', 'business_unit_price' => '0', 'effective_date' => '2027-02-01']));
expectError($G, 'tier gap (0–50, 60–100)', 'tiers.1', $mk([['tier_from' => '0', 'tier_to' => '50', 'unit_price' => '1'], ['tier_from' => '60', 'tier_to' => '100', 'unit_price' => '2'], ['tier_from' => '100', 'tier_to' => '', 'unit_price' => '3']]), 'bị hở');
expectError($G, 'tier overlap (0–50, 40–100)', 'tiers.1', $mk([['tier_from' => '0', 'tier_to' => '50', 'unit_price' => '1'], ['tier_from' => '40', 'tier_to' => '100', 'unit_price' => '2'], ['tier_from' => '100', 'tier_to' => '', 'unit_price' => '3']]), 'chồng lấn');
expectError($G, 'tier boundary kiểu cũ +1 (0–50, 51–100) bị coi là hở', 'tiers.1', $mk([['tier_from' => '0', 'tier_to' => '50', 'unit_price' => '1'], ['tier_from' => '51', 'tier_to' => '100', 'unit_price' => '2'], ['tier_from' => '100', 'tier_to' => '', 'unit_price' => '3']]), 'không +1');
expectError($G, 'missing final open tier (bậc cuối có "Đến")', 'tiers.2', $mk([['tier_from' => '0', 'tier_to' => '50', 'unit_price' => '1'], ['tier_from' => '50', 'tier_to' => '100', 'unit_price' => '2'], ['tier_from' => '100', 'tier_to' => '400', 'unit_price' => '3']]), 'Bậc cuối phải không giới hạn trên');
expectError($G, 'bậc 1 không bắt đầu từ 0', 'tiers.0', $mk([['tier_from' => '10', 'tier_to' => '', 'unit_price' => '1']]), 'bắt đầu từ 0');
expectError($G, '"Đến" ≤ "Từ"', 'tiers.0', $mk([['tier_from' => '0', 'tier_to' => '0', 'unit_price' => '1'], ['tier_from' => '0', 'tier_to' => '', 'unit_price' => '2']]), 'phải lớn hơn');
expectError($G, 'bậc giữa để trống "Đến"', 'tiers.0', $mk([['tier_from' => '0', 'tier_to' => '', 'unit_price' => '1'], ['tier_from' => '50', 'tier_to' => '', 'unit_price' => '2']]), 'chỉ bậc cuối');
expectError($G, 'không có bậc nào', 'tiers', $mk([]));
expectError($G, 'VAT 150%', 'vat_percent', $mk(br13Tiers(), ['vat_percent' => '150']));
expectError($G, 'utility không hợp lệ (gas)', 'utility_type', $mk(br13Tiers(), ['utility_type' => 'gas']));
expectError($G, 'usage không hợp lệ', 'usage_type', $mk(br13Tiers(), ['usage_type' => 'cong_nghiep']));
expectError($G, 'ngày hiệu lực trong kỳ đã khóa 06/2024 (VAL-03.04)', 'effective_date', $mk(br13Tiers(), ['effective_date' => '2024-06-15']), 'kỳ đã khóa');
expectError($G, 'ngày hiệu lực trước kỳ đã khóa (hồi tố - BR-42)', 'effective_date', $mk(br13Tiers(), ['effective_date' => '2024-03-01']), 'kỳ đã khóa');
expectError($G, 'trùng ngày hiệu lực trong chuỗi (2027-01-01)', 'effective_date', $mk(br13Tiers(), ['effective_date' => '2027-01-01']), 'đã có phiên bản');

// Historical version integrity
check($G, 'historical: phiên bản seed #1 + bậc giữ nguyên sau khi tạo phiên bản mới',
    $pdo->query('SELECT tariff_id, tier_order, tier_from, tier_to, unit_price FROM tariff_tiers WHERE tariff_id = 1 ORDER BY tier_order')->fetchAll() === $seedTier
    && $pdo->query('SELECT * FROM tariffs WHERE id = 1')->fetch() === $seedRow);
$seed = $tariffs->find(1);
expectError($G, 'historical: không sửa được phiên bản đã áp dụng (#1, 2024)', '_', fn () => $tariffs->update(1, ['vat_percent' => '8', 'effective_date' => '2024-01-01', 'version_token' => $seed['version_token'], 'tiers' => br13Tiers()]), 'không sửa đè lịch sử');

$v2 = $tariffs->find((int) $t2);
$edited = br13Tiers(); $edited[5]['unit_price'] = '3200';
check($G, 'sửa phiên bản Sắp áp dụng (bậc 6 = 3.200) -> true', expectOk($G, 'update v2', fn () => $tariffs->update((int) $t2, ['vat_percent' => '8', 'effective_date' => '2027-01-01', 'version_token' => $v2['version_token'], 'tiers' => $edited])) === true
    && q($pdo, 'SELECT unit_price FROM tariff_tiers WHERE tariff_id = ? AND tier_order = 6', [$t2]) === '3200.00');
check($G, 'update: audit UPDATE_TARIFF lưu old/new bậc', (function () use ($pdo, $t2): bool {
    $r = $pdo->query("SELECT old_value, new_value FROM audit_logs WHERE action='UPDATE_TARIFF' AND entity_id='{$t2}' ORDER BY id DESC LIMIT 1")->fetch();
    return $r && json_decode($r['old_value'], true)['tiers'][5]['unit_price'] === '3151.00' && json_decode($r['new_value'], true)['tiers'][5]['unit_price'] === '3200.00';
})());
expectError($G, 'update với version_token cũ -> chặn ghi đè', '_', fn () => $tariffs->update((int) $t2, ['vat_percent' => '8', 'effective_date' => '2027-01-01', 'version_token' => $v2['version_token'], 'tiers' => br13Tiers()]), 'người khác thay đổi');
$v2 = $tariffs->find((int) $t2);
check($G, 'update không đổi gì -> false', $tariffs->update((int) $t2, ['vat_percent' => '8', 'effective_date' => '2027-01-01', 'version_token' => $v2['version_token'], 'tiers' => $edited]) === false);
expectError($G, 'update dời ngày hiệu lực vào kỳ khóa', 'effective_date', fn () => $tariffs->update((int) $t2, ['vat_percent' => '8', 'effective_date' => '2024-06-01', 'version_token' => $v2['version_token'], 'tiers' => $edited]));

// ============================================================ PROPOSAL (FN-03.03 / 03.04)
$G = 'PROPOSAL';
actAs(3, 'ke_toan');
$tariffCount = (int) q($pdo, 'SELECT COUNT(*) FROM tariffs');
$propTiers = br13Tiers(); $propTiers[0]['unit_price'] = '1900';
$p1 = expectOk($G, 'accountant create (điện sinh hoạt, dựa trên v2 mới nhất, 2027-06-01)', fn () => $proposals->create((int) $t2, ['vat_percent' => '8', 'effective_date' => '2027-06-01', 'reason' => 'EVN điều chỉnh giá', 'tiers' => $propTiers]));
check($G, 'create: Chờ duyệt, proposed_by = Kế toán, biểu giá hiện hành KHÔNG đổi, audit CREATE_TARIFF_PROPOSAL',
    q($pdo, 'SELECT CONCAT(status, proposed_by) FROM tariff_proposals WHERE id = ?', [$p1]) === 'cho_duyet3'
    && (int) q($pdo, 'SELECT COUNT(*) FROM tariffs') === $tariffCount && auditCount($pdo, 'CREATE_TARIFF_PROPOSAL', 'tariff_proposal', (int) $p1) === 1);
expectError($G, 'duplicate pending cùng chuỗi (VAL-03.05)', '_', fn () => $proposals->create((int) $t2, ['vat_percent' => '8', 'effective_date' => '2027-07-01', 'tiers' => br13Tiers()]), 'đang có đề xuất chờ duyệt');
expectError($G, 'đề xuất trên phiên bản cũ (#1) khi đã có v2', '_', fn () => $proposals->create(1, ['vat_percent' => '8', 'effective_date' => '2027-07-01', 'tiers' => br13Tiers()]), 'phiên bản mới nhất');
expectError($G, 'target tariff không tồn tại', '_', fn () => $proposals->create(999999, ['vat_percent' => '8', 'effective_date' => '2027-07-01', 'tiers' => br13Tiers()]), 'không tồn tại');
expectError($G, 'giá đề xuất không hợp lệ (bậc hở)', 'tiers.1', fn () => $proposals->create(3, ['vat_percent' => '', 'effective_date' => '2027-07-01', 'tiers' => [['tier_from' => '0', 'tier_to' => '10', 'unit_price' => '1'], ['tier_from' => '11', 'tier_to' => '', 'unit_price' => '2']]]));
expectError($G, 'ngày hiệu lực đề xuất trong kỳ khóa', 'effective_date', fn () => $proposals->create(3, ['vat_percent' => '', 'effective_date' => '2024-06-10', 'tiers' => [['tier_from' => '0', 'tier_to' => '', 'unit_price' => '1']]]));

actAs(1, 'bql');
$newTariff = expectOk($G, 'BQL approve', fn () => $proposals->approve((int) $p1));
check($G, 'approve: phiên bản mới 2027-06-01, created_by = Kế toán, approved_by = BQL, bậc 1 = 1.900',
    q($pdo, 'SELECT CONCAT(effective_date, "|", created_by, "|", approved_by) FROM tariffs WHERE id = ?', [$newTariff]) === '2027-06-01|3|1'
    && q($pdo, 'SELECT unit_price FROM tariff_tiers WHERE tariff_id = ? AND tier_order = 1', [$newTariff]) === '1900.00');
check($G, 'approve: đề xuất Đã duyệt, reviewed_by = BQL, audit APPROVE + CREATE_TARIFF',
    q($pdo, 'SELECT CONCAT(status, reviewed_by) FROM tariff_proposals WHERE id = ?', [$p1]) === 'da_duyet1'
    && auditCount($pdo, 'APPROVE_TARIFF_PROPOSAL', 'tariff_proposal', (int) $p1) === 1 && auditCount($pdo, 'CREATE_TARIFF', 'tariff', (int) $newTariff) === 1);
check($G, 'approve: phiên bản trước (v2) giữ nguyên', q($pdo, 'SELECT unit_price FROM tariff_tiers WHERE tariff_id = ? AND tier_order = 1', [$t2]) === '1806.00');
expectError($G, 'approve twice -> "đã được xử lý"', '_', fn () => $proposals->approve((int) $p1), 'đã được xử lý');

actAs(3, 'ke_toan');
$p2 = $proposals->create(3, ['vat_percent' => '', 'effective_date' => '2027-01-01', 'reason' => 'Giá nước mới', 'tiers' => [['tier_from' => '0', 'tier_to' => '10', 'unit_price' => '12000'], ['tier_from' => '10', 'tier_to' => '', 'unit_price' => '15000']]]);
actAs(1, 'bql');
expectError($G, 'reject missing reason (VAL-03.06)', 'rejection_reason', fn () => $proposals->reject($p2, '   '));
$waterCount = (int) q($pdo, "SELECT COUNT(*) FROM tariffs WHERE utility_type='nuoc'");
expectOk($G, 'BQL reject có lý do', fn () => $proposals->reject($p2, 'Chưa có văn bản của nhà cung cấp'));
check($G, 'reject: Từ chối, lưu lý do, biểu giá không đổi, audit REJECT', q($pdo, 'SELECT CONCAT(status, "|", rejection_reason) FROM tariff_proposals WHERE id = ?', [$p2]) === 'tu_choi|Chưa có văn bản của nhà cung cấp'
    && (int) q($pdo, "SELECT COUNT(*) FROM tariffs WHERE utility_type='nuoc'") === $waterCount && auditCount($pdo, 'REJECT_TARIFF_PROPOSAL', 'tariff_proposal', $p2) === 1);
expectError($G, 'reject đề xuất đã xử lý', '_', fn () => $proposals->reject($p2, 'x'), 'đã được xử lý');

// Hai BQL duyệt đồng thời: 2 tiến trình PHP thật, cùng xuất phát
actAs(3, 'ke_toan');
$p3 = $proposals->create((int) $tKd, ['vat_percent' => '8', 'business_unit_price' => '4100', 'effective_date' => '2027-03-01', 'reason' => 'race']);
$start = microtime(true) + 1.5;
$php = PHP_BINARY;
$procs = [];
foreach ([1, 1] as $i => $actor) {
    $cmd = sprintf('%s %s %s %d %d %F', escapeshellarg($php), escapeshellarg(__DIR__ . '/support/fn03_approve_worker.php'), escapeshellarg($testDb), $p3, $actor, $start);
    $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$outputs = [];
foreach ($procs as $i => $proc) {
    $outputs[$i] = json_decode((string) stream_get_contents($pipes[$i][1]), true) ?? ['ok' => false, 'message' => stream_get_contents($pipes[$i][2])];
    proc_close($proc);
}
$okCount = count(array_filter($outputs, static fn ($o) => $o['ok']));
$loser = array_values(array_filter($outputs, static fn ($o) => !$o['ok']))[0]['message'] ?? '';
check($G, 'two simultaneous approve: đúng 1 thành công, 1 bị từ chối "đã được xử lý"', $okCount === 1 && str_contains($loser, 'đã được xử lý'), json_encode($outputs, JSON_UNESCAPED_UNICODE));
check($G, 'two simultaneous approve: chỉ tạo 1 phiên bản cho 2027-03-01, 1 audit APPROVE',
    (int) q($pdo, "SELECT COUNT(*) FROM tariffs WHERE utility_type='dien' AND usage_type='kinh_doanh' AND effective_date='2027-03-01'") === 1
    && auditCount($pdo, 'APPROVE_TARIFF_PROPOSAL', 'tariff_proposal', $p3) === 1);

// Technical safeguard P11 (không phải trạng thái nghiệp vụ)
actAs(3, 'ke_toan');
$p4 = $proposals->create(4, ['vat_percent' => '', 'business_unit_price' => '26000', 'effective_date' => '2027-05-01', 'reason' => 'stale']);
sleep(1); // updated_at độ phân giải giây
actAs(1, 'bql');
$tariffs->create(['utility_type' => 'nuoc', 'usage_type' => 'kinh_doanh', 'vat_percent' => '', 'business_unit_price' => '25500', 'effective_date' => '2027-02-01']);
expectError($G, 'stale safeguard: chuỗi có phiên bản mới sau khi gửi đề xuất -> chặn Duyệt', '_', fn () => $proposals->approve($p4), 'an toàn kỹ thuật');
check($G, 'stale safeguard: đề xuất vẫn Chờ duyệt (không có trạng thái "Lỗi thời")', q($pdo, 'SELECT status FROM tariff_proposals WHERE id = ?', [$p4]) === 'cho_duyet');
expectOk($G, 'stale safeguard: BQL vẫn Từ chối được', fn () => $proposals->reject($p4, 'Đề xuất dựa trên giá cũ'));

actAs(3, 'ke_toan');
$p5 = $proposals->create((int) $newTariff, ['vat_percent' => '8', 'effective_date' => '2024-07-15', 'reason' => 'kỳ sắp khóa', 'tiers' => br13Tiers()]);
$pdo->exec("UPDATE billing_periods SET status = 'da_khoa', locked_at = NOW() WHERE id = 2"); // khoá kỳ 07/2024 sau khi gửi
actAs(1, 'bql');
expectError($G, 'safeguard: ngày hiệu lực đề xuất nay rơi vào kỳ vừa khóa -> chặn Duyệt', '_', fn () => $proposals->approve($p5), 'kỳ đã khóa');
$pdo->exec("UPDATE billing_periods SET status = 'mo', locked_at = NULL WHERE id = 2");

// ============================================================ FIXED FEE (FN-03.02)
$G = 'FIXED FEE';
actAs(1, 'bql');
$f1 = expectOk($G, 'create (gửi ô tô 550.000, 2027-01-01)', fn () => $fees->create(['fee_type' => 'gui_o_to', 'unit_price' => '550000', 'effective_date' => '2027-01-01']));
check($G, 'create: audit CREATE_FIXED_FEE, đơn giá hiện hành (seed 500.000) vẫn Đang áp dụng, bản mới Sắp áp dụng',
    auditCount($pdo, 'CREATE_FIXED_FEE', 'fixed_fee_rate', (int) $f1) === 1
    && (float) $fees->overview('gui_o_to')['gui_o_to']['current']['unit_price'] === 500000.0
    && (int) $fees->overview('gui_o_to')['gui_o_to']['upcoming'][0]['id'] === (int) $f1);
foreach (['0' => 'amount = 0', '-1' => 'amount âm', 'abc' => 'amount không phải số', '1.234' => 'amount 3 chữ số thập phân'] as $amount => $label) {
    expectError($G, "invalid {$label}", 'unit_price', fn () => $fees->create(['fee_type' => 'gui_xe_may', 'unit_price' => (string) $amount, 'effective_date' => '2027-01-01']));
}
expectError($G, 'effective date trong kỳ khóa', 'effective_date', fn () => $fees->create(['fee_type' => 'gui_xe_may', 'unit_price' => '60000', 'effective_date' => '2024-06-30']), 'kỳ đã khóa');
expectError($G, 'effective date không hợp lệ', 'effective_date', fn () => $fees->create(['fee_type' => 'gui_xe_may', 'unit_price' => '60000', 'effective_date' => '2027-02-30']));
expectError($G, 'trùng ngày hiệu lực cùng loại phí', 'effective_date', fn () => $fees->create(['fee_type' => 'gui_o_to', 'unit_price' => '600000', 'effective_date' => '2027-01-01']));
expectError($G, 'loại phí không hợp lệ', 'fee_type', fn () => $fees->create(['fee_type' => 'phi_ve_sinh', 'unit_price' => '1000', 'effective_date' => '2027-01-01']));
$fee = $fees->find((int) $f1);
check($G, 'update đơn giá Sắp áp dụng -> true + audit UPDATE_FIXED_FEE', expectOk($G, 'update', fn () => $fees->update((int) $f1, ['unit_price' => '560000', 'effective_date' => '2027-01-01', 'version_token' => $fee['version_token']])) === true
    && auditCount($pdo, 'UPDATE_FIXED_FEE', 'fixed_fee_rate', (int) $f1) === 1);
expectError($G, 'update với version_token cũ -> chặn', '_', fn () => $fees->update((int) $f1, ['unit_price' => '570000', 'effective_date' => '2027-01-01', 'version_token' => $fee['version_token']]), 'người khác thay đổi');
$seedFee = $fees->find((int) q($pdo, "SELECT id FROM fixed_fee_rates WHERE fee_type='gui_o_to' AND effective_date='2024-01-01'"));
expectError($G, 'không sửa được đơn giá đã áp dụng (seed 2024)', '_', fn () => $fees->update((int) $seedFee['id'], ['unit_price' => '1', 'effective_date' => '2024-01-01', 'version_token' => $seedFee['version_token']]), 'không sửa đè lịch sử');

// ------------------------------------------------------------------ kết quả
$failed = 0;
foreach ($results as [$group, $name, $ok, $detail]) {
    $failed += $ok ? 0 : 1;
    printf("%s  %-11s %s%s\n", $ok ? 'PASS' : 'FAIL', $group, $name, $ok || $detail === '' ? '' : "  -> {$detail}");
}
printf("\n%d/%d PASS (service test, db=%s)\n", count($results) - $failed, count($results), $testDb);
exit($failed === 0 ? 0 : 1);
