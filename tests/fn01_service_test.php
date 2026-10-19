<?php

declare(strict_types=1);

/**
 * STEP 15 - Test cấp Service FN-01.03 (tài khoản), FN-01.06 (tra cứu audit), FN-01.07 (tham số hệ thống) + đồng thời.
 * Chạy trên DATABASE TẠM:  php tests/fn01_service_test.php energy_dss_fn01_test
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
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Services\SystemParameterService;
use App\Services\UserService;

$pdo = Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
    fwrite(STDERR, "Không trỏ được sang DB tạm - dừng.\n");
    exit(2);
}
$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
const BQL = 1;
const KT = 2;
const PW = 'NewPass@2026x';

function actAs(int $id, string $role): void
{
    $_SESSION = ['user_id' => $id, 'user_role' => $role, 'username' => $role, 'full_name' => $role];
}

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function expectError(string $g, string $n, string $field, callable $fn, string $contains = ''): void
{
    try {
        $fn();
        check($g, $n, false, 'không có lỗi');
    } catch (ValidationException $e) {
        $msg = json_encode($e->errors(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ok = isset($e->errors()[$field]) && ($contains === '' || str_contains($msg, $contains));
        check($g, $n, $ok, $ok ? '' : $msg);
    }
}

function expectOk(string $g, string $n, callable $fn)
{
    try {
        $r = $fn();
        check($g, $n, true);

        return $r;
    } catch (ValidationException $e) {
        check($g, $n, false, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

        return null;
    }
}

function q(PDO $pdo, string $sql, array $p = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($p);

    return $st->fetchColumn();
}

function userRow(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);

    return $st->fetch();
}

function lastAudit(PDO $pdo, string $action): ?array
{
    $st = $pdo->prepare('SELECT * FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$action]);

    return $st->fetch() ?: null;
}

function race(string $db, array $jobs): array
{
    $start = microtime(true) + 1.5;
    $procs = [];
    $pipes = [];
    foreach ($jobs as $i => $job) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/fn01_worker.php') . ' ' . escapeshellarg($db) . ' ' . sprintf('%F', $start)
            . ' ' . implode(' ', array_map('escapeshellarg', array_map('strval', $job)));
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $p) {
        $raw = stream_get_contents($pipes[$i][1]);
        $out[$i] = json_decode((string) $raw, true) ?? ['ok' => false, 'message' => $raw . stream_get_contents($pipes[$i][2])];
        proc_close($p);
    }

    return $out;
}

function okCount(array $out): int
{
    return count(array_filter($out, static fn ($o) => $o['ok']));
}

$users = new UserService();
$params = new SystemParameterService();
actAs(BQL, 'bql');

// =========================================================== FN-01.03 TẠO TÀI KHOẢN
$G = 'USER-CREATE';
$base = ['username' => 'nv.moi01', 'full_name' => 'Nhân viên mới', 'role' => 'ky_thuat'];
foreach (['' => 'rỗng', 'ab' => '2 ký tự', 'co dau cach' => 'dấu cách', '.batdau' => 'bắt đầu bằng dấu chấm', 'người' => 'ký tự có dấu', str_repeat('a', 51) => '51 ký tự', "a'; DROP TABLE users;--" => 'SQL injection'] as $bad => $label) {
    expectError($G, "username {$label} -> lỗi", 'username', fn () => $users->create(['username' => $bad] + $base, PW, PW));
}
expectError($G, 'họ tên rỗng -> lỗi', 'full_name', fn () => $users->create(['full_name' => ''] + $base, PW, PW));
expectError($G, 'họ tên 151 ký tự -> lỗi', 'full_name', fn () => $users->create(['full_name' => str_repeat('x', 151)] + $base, PW, PW));
foreach (['', 'admin', 'superadmin', 'BQL', 'bql '] as $bad) {
    expectError($G, "vai trò '{$bad}' -> lỗi", 'role', fn () => $users->create(['role' => $bad] + $base, PW, PW));
}
foreach (['' => 'rỗng', 'Abc1234' => '7 ký tự', 'abcdefg1' => 'không chữ hoa', 'Abcdefgh' => 'không chữ số', 'A1' . str_repeat('x', 71) => '73 byte', "Abcdefg1\x01" => 'ký tự điều khiển'] as $bad => $label) {
    expectError($G, "mật khẩu {$label} -> lỗi", 'password', fn () => $users->create($base, $bad, $bad));
}
expectError($G, 'xác nhận mật khẩu không khớp -> lỗi', 'password_confirmation', fn () => $users->create($base, PW, PW . 'x'));
check($G, 'mọi lỗi trên: không tạo bản ghi nào', (int) q($pdo, 'SELECT COUNT(*) FROM users') === 3);
check($G, 'đúng 3 vai trò: nhãn (master_data) = ENUM users.role = bql, ky_thuat, ke_toan', array_keys(config('master_data.user_role')) === ['bql', 'ky_thuat', 'ke_toan']
    && q($pdo, "SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'") === "enum('bql','ky_thuat','ke_toan')");
try {
    $pdo->exec("INSERT INTO users (username, password_hash, full_name, role) VALUES ('raw_admin', 'x', 'x', 'admin')");
    check($G, 'DB từ chối role = admin (ENUM, strict mode)', false);
} catch (PDOException $e) {
    check($G, 'DB từ chối role = admin (ENUM, strict mode)', (int) q($pdo, "SELECT COUNT(*) FROM users WHERE username = 'raw_admin'") === 0);
}
$newId = expectOk($G, 'tạo hợp lệ (Kỹ thuật)', fn () => $users->create($base, PW, PW));
$row = userRow($pdo, (int) $newId);
check($G, 'lưu bcrypt (password_verify đúng), không lưu bản rõ', password_verify(PW, $row['password_hash']) && !str_contains($row['password_hash'], PW) && str_starts_with($row['password_hash'], '$2y$'));
check($G, 'trạng thái hoat_dong, created_by = người tạo', $row['status'] === 'hoat_dong' && (int) $row['created_by'] === BQL);
$a = lastAudit($pdo, 'CREATE_USER');
check($G, 'audit CREATE_USER: username/role, KHÔNG có mật khẩu / hash', $a !== null && (int) $a['entity_id'] === (int) $newId && str_contains($a['new_value'], 'nv.moi01')
    && !str_contains($a['new_value'] . $a['old_value'], PW) && !str_contains((string) $a['new_value'], 'password') && !str_contains((string) $a['new_value'], '$2y$'));
expectError($G, 'trùng username -> lỗi (UNIQUE)', 'username', fn () => $users->create($base, PW, PW), 'đã tồn tại');
expectError($G, 'trùng username khác hoa thường (collation ci) -> lỗi', 'username', fn () => $users->create(['username' => 'NV.MOI01'] + $base, PW, PW), 'đã tồn tại');
try {
    $users->create(['username' => ''] + $base, 'Secret#Leak9', 'Secret#Leak9');
} catch (ValidationException $e) {
    check($G, 'stack trace exception KHÔNG chứa mật khẩu (#[\SensitiveParameter])', !str_contains($e->getTraceAsString(), 'Secret#Leak9') && !str_contains((string) $e, 'Secret#Leak9'));
}
$login = (new AuthService())->attemptLogin('nv.moi01', PW);
check($G, 'tài khoản mới đăng nhập được bằng mật khẩu khởi tạo', $login['success'] === true);
actAs(BQL, 'bql');

// =========================================================== FN-01.03 SỬA
$G = 'USER-UPDATE';
$u = $users->find((int) $newId);
check($G, 'find() không trả password_hash', !array_key_exists('password_hash', $u));
expectError($G, 'token cũ / sai -> từ chối', '_', fn () => $users->update((int) $newId, ['full_name' => 'X', 'role' => 'ky_thuat'], 'deadbeef'), 'người khác');
expectError($G, 'không có thay đổi -> lỗi', '_', fn () => $users->update((int) $newId, ['full_name' => 'Nhân viên mới', 'role' => 'ky_thuat'], $u['version_token']), 'Không có thay đổi');
expectOk($G, 'đổi họ tên + vai trò Kế toán', fn () => $users->update((int) $newId, ['full_name' => 'NV Kế toán', 'role' => 'ke_toan'], $u['version_token']));
$row = userRow($pdo, (int) $newId);
check($G, 'DB đã đổi', $row['full_name'] === 'NV Kế toán' && $row['role'] === 'ke_toan');
$a = lastAudit($pdo, 'UPDATE_USER');
check($G, 'audit UPDATE_USER có cũ / mới', $a !== null && str_contains($a['old_value'], 'ky_thuat') && str_contains($a['new_value'], 'ke_toan'));
expectError($G, 'token của lần mở trước (đã sửa) -> từ chối', '_', fn () => $users->update((int) $newId, ['full_name' => 'Y', 'role' => 'ke_toan'], $u['version_token']), 'người khác');
$self = $users->find(BQL);
expectError($G, 'tự đổi vai trò của mình -> từ chối', 'role', fn () => $users->update(BQL, ['full_name' => $self['full_name'], 'role' => 'ke_toan'], $self['version_token']), 'chính mình');
expectOk($G, 'tự đổi họ tên của mình -> cho phép', fn () => $users->update(BQL, ['full_name' => 'BQL Một', 'role' => 'bql'], $self['version_token']));
expectError($G, 'vai trò không hợp lệ -> lỗi', 'role', fn () => $users->update((int) $newId, ['full_name' => 'Z', 'role' => 'root'], $users->find((int) $newId)['version_token']));

// =========================================================== FN-01.03 TRẠNG THÁI
$G = 'USER-STATUS';
$tok = fn (int $id) => $users->find($id)['version_token'];
expectError($G, 'thao tác lạ "delete" -> từ chối (không xóa cứng)', '_', fn () => $users->changeStatus((int) $newId, 'delete', $tok((int) $newId)));
expectError($G, 'mở khóa tài khoản đang hoạt động -> từ chối', '_', fn () => $users->changeStatus((int) $newId, 'unlock', $tok((int) $newId)));
expectError($G, 'tự vô hiệu hóa chính mình -> từ chối', '_', fn () => $users->changeStatus(BQL, 'deactivate', $tok(BQL)), 'chính mình');
expectOk($G, 'vô hiệu hóa', fn () => $users->changeStatus((int) $newId, 'deactivate', $tok((int) $newId)));
check($G, 'trạng thái ngung, bản ghi vẫn còn (không xóa cứng)', userRow($pdo, (int) $newId)['status'] === 'ngung');
check($G, 'đăng nhập tài khoản ngung -> "không còn hiệu lực"', str_contains((string) (new AuthService())->attemptLogin('nv.moi01', PW)['message'], 'không còn hiệu lực'));
actAs(BQL, 'bql');
$a = lastAudit($pdo, 'CHANGE_USER_STATUS');
check($G, 'audit CHANGE_USER_STATUS hoat_dong -> ngung', $a !== null && str_contains($a['old_value'], 'hoat_dong') && str_contains($a['new_value'], 'ngung') && str_contains($a['new_value'], 'deactivate'));
expectError($G, 'vô hiệu hóa lần nữa -> từ chối', '_', fn () => $users->changeStatus((int) $newId, 'deactivate', $tok((int) $newId)));
expectOk($G, 'kích hoạt lại', fn () => $users->changeStatus((int) $newId, 'activate', $tok((int) $newId)));
$pdo->exec("UPDATE users SET status = 'khoa_tam', failed_login_count = 5, locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = " . (int) $newId);
expectOk($G, 'mở khóa tài khoản khóa tạm', fn () => $users->changeStatus((int) $newId, 'unlock', $tok((int) $newId)));
$row = userRow($pdo, (int) $newId);
check($G, 'mở khóa: hoat_dong, failed_login_count = 0, locked_until NULL', $row['status'] === 'hoat_dong' && (int) $row['failed_login_count'] === 0 && $row['locked_until'] === null);
$pdo->exec("UPDATE users SET status = 'khoa_tam', locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = " . (int) $newId);
expectOk($G, 'vô hiệu hóa tài khoản đang khóa tạm (nghỉ việc)', fn () => $users->changeStatus((int) $newId, 'deactivate', $tok((int) $newId)));
$users->changeStatus((int) $newId, 'activate', $tok((int) $newId));
// BQL cuối cùng: actor KHÔNG phải BQL hoạt động (gọi thẳng Service, bỏ qua RBAC) -> vẫn chặn
actAs(KT, 'ky_thuat');
expectError($G, 'vô hiệu hóa BQL hoạt động cuối cùng -> từ chối', '_', fn () => $users->changeStatus(BQL, 'deactivate', $tok(BQL)), 'ít nhất một');
expectError($G, 'hạ vai trò BQL hoạt động cuối cùng -> từ chối', '_', fn () => $users->update(BQL, ['full_name' => 'BQL Một', 'role' => 'ke_toan'], $tok(BQL)), 'ít nhất một');
actAs(BQL, 'bql');
check($G, 'vẫn còn đúng 1 BQL hoạt động', (int) q($pdo, "SELECT COUNT(*) FROM users WHERE role = 'bql' AND status = 'hoat_dong'") === 1);

// =========================================================== FN-01.03 ĐẶT LẠI MẬT KHẨU
$G = 'USER-PASSWORD';
expectError($G, 'mật khẩu yếu -> lỗi', 'password', fn () => $users->resetPassword((int) $newId, 'abc', 'abc', $tok((int) $newId)));
expectError($G, 'trùng mật khẩu hiện tại -> lỗi', 'password', fn () => $users->resetPassword((int) $newId, PW, PW, $tok((int) $newId)), 'khác mật khẩu hiện tại');
expectError($G, 'token sai -> từ chối', '_', fn () => $users->resetPassword((int) $newId, 'Reset@2026a', 'Reset@2026a', 'x'));
$before = userRow($pdo, (int) $newId)['password_hash'];
$t0 = $tok((int) $newId);
expectOk($G, 'đặt lại hợp lệ', fn () => $users->resetPassword((int) $newId, 'Reset@2026a', 'Reset@2026a', $t0));
$row = userRow($pdo, (int) $newId);
check($G, 'hash mới khác hash cũ, mật khẩu mới đúng', $row['password_hash'] !== $before && password_verify('Reset@2026a', $row['password_hash']));
check($G, 'token cũ hết hiệu lực (vân tay mật khẩu nằm trong token)', $tok((int) $newId) !== $t0);
$a = lastAudit($pdo, 'RESET_USER_PASSWORD');
check($G, 'audit RESET_USER_PASSWORD không chứa mật khẩu / hash', $a !== null && !str_contains($a['new_value'] . $a['old_value'], 'Reset@2026a') && !str_contains($a['new_value'] . $a['old_value'], '$2y$'));
check($G, 'mật khẩu cũ không còn đăng nhập được', (new AuthService())->attemptLogin('nv.moi01', PW)['success'] === false);
actAs(BQL, 'bql');
$_SESSION['auth_fp'] = 'old';
expectOk($G, 'BQL tự đặt lại mật khẩu của mình', fn () => $users->resetPassword(BQL, 'Self@2026b', 'Self@2026b', $tok(BQL)));
check($G, 'tự đặt lại: vân tay phiên hiện tại được cập nhật (không tự đá mình ra)', ($_SESSION['auth_fp'] ?? '') === \App\Middleware\AuthMiddleware::fingerprint(userRow($pdo, BQL)['password_hash']));
check($G, 'không có mật khẩu bản rõ nào trong audit_logs', (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE CONCAT(IFNULL(old_value,''), IFNULL(new_value,'')) REGEXP 'NewPass@2026x|Reset@2026a|Self@2026b|Secret#Leak9|\\\\$2y\\\\$'") === 0);

// =========================================================== FN-01.07 THAM SỐ
$G = 'PARAM';
$list = $params->list();
check($G, 'danh sách = đúng 5 khóa whitelist', array_column($list, 'key') === ['session_timeout_minutes', 'max_failed_login_attempts', 'account_lock_duration_minutes', 'bank_account_info', 'import_strict_mode']);
$ids = array_column(array_map(fn ($p) => ['k' => $p['key'], 'id' => (int) $p['row']['id']], $list), 'id', 'k');
$pTok = fn (string $k) => $params->find($ids[$k])['version_token'];
foreach (['abc', '4', '481', '-5', '5.5', '', '1e2', '0x1F', '30 OR 1=1'] as $bad) {
    expectError($G, "session_timeout '{$bad}' -> lỗi", 'config_value', fn () => $params->update($ids['session_timeout_minutes'], $bad, $pTok('session_timeout_minutes')));
}
foreach (['2', '21'] as $bad) {
    expectError($G, "max_failed_login_attempts '{$bad}' (ngoài 3–20) -> lỗi", 'config_value', fn () => $params->update($ids['max_failed_login_attempts'], $bad, $pTok('max_failed_login_attempts')));
}
expectError($G, 'import_strict_mode "2" -> lỗi', 'config_value', fn () => $params->update($ids['import_strict_mode'], '2', $pTok('import_strict_mode')));
expectError($G, 'bank_account_info 256 ký tự -> lỗi', 'config_value', fn () => $params->update($ids['bank_account_info'], str_repeat('x', 256), $pTok('bank_account_info')));
expectError($G, 'bank_account_info có ký tự điều khiển -> lỗi', 'config_value', fn () => $params->update($ids['bank_account_info'], "TK\x07 123", $pTok('bank_account_info')));
expectError($G, 'token sai -> từ chối', '_', fn () => $params->update($ids['session_timeout_minutes'], '45', 'x'));
expectError($G, 'giá trị không đổi -> lỗi', 'config_value', fn () => $params->update($ids['session_timeout_minutes'], '30', $pTok('session_timeout_minutes')));
SystemParameterService::flush();
check($G, 'trước khi sửa: get(session_timeout_minutes) = 30', SystemParameterService::get('session_timeout_minutes') === '30');
expectOk($G, 'sửa session_timeout_minutes "045" -> lưu 45', fn () => $params->update($ids['session_timeout_minutes'], '045', $pTok('session_timeout_minutes')));
check($G, 'DB lưu "45" (chuẩn hóa) và get() thấy ngay (không khởi động lại)', q($pdo, "SELECT config_value FROM system_configs WHERE config_key = 'session_timeout_minutes'") === '45' && SystemParameterService::int('session_timeout_minutes') === 45);
$a = lastAudit($pdo, 'UPDATE_SYSTEM_PARAMETER');
check($G, 'audit UPDATE_SYSTEM_PARAMETER có khóa, cũ 30, mới 45, updated_by', $a !== null && str_contains($a['old_value'], '"30"') && str_contains($a['new_value'], '"45"') && str_contains($a['new_value'], 'session_timeout_minutes')
    && (int) q($pdo, "SELECT updated_by FROM system_configs WHERE config_key = 'session_timeout_minutes'") === BQL);
expectOk($G, 'bank_account_info hợp lệ', fn () => $params->update($ids['bank_account_info'], 'TK 0123456789 - Ngân hàng A', $pTok('bank_account_info')));
expectOk($G, 'import_strict_mode -> 1', fn () => $params->update($ids['import_strict_mode'], '1', $pTok('import_strict_mode')));
$isStrict = new ReflectionMethod(\App\Services\ReadingImportService::class, 'isStrictMode');
check($G, 'ReadingImportService đọc import_strict_mode qua whitelist = 1', $isStrict->invoke(new \App\Services\ReadingImportService()) === true);
// giá trị lưu hỏng trong DB -> dùng mặc định (.env / whitelist), không sập
$pdo->exec("UPDATE system_configs SET config_value = 'abc' WHERE config_key = 'max_failed_login_attempts'");
SystemParameterService::flush();
check($G, 'giá trị lưu không hợp lệ ("abc") -> fallback .env = 5', SystemParameterService::get('max_failed_login_attempts') === '5');
$pdo->exec("UPDATE system_configs SET config_value = '5' WHERE config_key = 'max_failed_login_attempts'");
// khoá ngoài whitelist trong bảng -> không xem / sửa được
$pdo->exec("INSERT INTO system_configs (config_key, config_value) VALUES ('app_debug', 'false')");
$evilId = (int) $pdo->lastInsertId();
check($G, 'khóa ngoài whitelist (app_debug) -> find() = null (404)', $params->find($evilId) === null);
expectError($G, 'khóa ngoài whitelist -> update từ chối, giá trị giữ nguyên', '_', fn () => $params->update($evilId, 'true', 'x'));
check($G, 'app_debug giữ nguyên "false"', q($pdo, 'SELECT config_value FROM system_configs WHERE id = ?', [$evilId]) === 'false');
check($G, 'khóa ngoài whitelist không xuất hiện trong list()', !in_array('app_debug', array_column($params->list(), 'key'), true));
check($G, 'whitelist không có khóa triển khai (.env: APP_DEBUG, APP_KEY, DB_*, đường dẫn)', array_filter(array_keys(config('system_parameters')), fn ($k) => preg_match('/debug|app_key|db_|password|path|secret|host/i', $k)) === []);
try {
    SystemParameterService::get('APP_DEBUG');
    check($G, 'get() khóa ngoài whitelist -> exception', false);
} catch (InvalidArgumentException $e) {
    check($G, 'get() khóa ngoài whitelist -> exception', true);
}
// tham số có hiệu lực ở lần đăng nhập sai kế tiếp
expectOk($G, 'max_failed_login_attempts -> 3', fn () => $params->update($ids['max_failed_login_attempts'], '3', $pTok('max_failed_login_attempts')));
expectOk($G, 'account_lock_duration_minutes -> 2', fn () => $params->update($ids['account_lock_duration_minutes'], '2', $pTok('account_lock_duration_minutes')));
SystemParameterService::flush();
$auth = new AuthService();
for ($i = 0; $i < 3; $i++) {
    $res = $auth->attemptLogin('nv.moi01', 'Wrong@1234');
}
$row = userRow($pdo, (int) $newId);
check($G, 'sai 3 lần (tham số mới = 3) -> khóa tạm', $row['status'] === 'khoa_tam');
check($G, 'thời gian khóa = 2 phút (tham số mới)', abs(strtotime((string) $row['locked_until']) - time() - 120) <= 5, (string) $row['locked_until']);
actAs(BQL, 'bql');
// DB không kết nối được -> fallback .env (process riêng)
$probe = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . dirname(__DIR__) . '/bootstrap.php"; $_ENV["DB_DATABASE"] = "khong_ton_tai_fn01"; restore_error_handler(); echo App\Services\SystemParameterService::get("session_timeout_minutes"), "|", App\Services\SystemParameterService::get("bank_account_info");');
$out = trim((string) shell_exec($probe . ' 2>/dev/null')); // stderr = error_log [DB CONNECTION ERROR] (ghi log máy chủ, đúng)
check($G, 'DB lỗi -> get() fallback .env / mặc định, không exception', $out === '30|Chưa cấu hình', $out);

// =========================================================== FN-01.06 TRA CỨU AUDIT
$G = 'AUDIT-SEARCH';
$logs = new AuditLogService();
$r = $logs->search(['action' => 'CREATE_USER'], 1);
check($G, 'lọc action CREATE_USER', $r['total'] >= 1 && array_unique(array_column($r['data'], 'action')) === ['CREATE_USER']);
$r = $logs->search(['entity_type' => 'users', 'entity_id' => (string) $newId], 1);
check($G, 'lọc đối tượng users #id: có đủ CREATE/UPDATE/CHANGE_STATUS/RESET', count(array_intersect(['CREATE_USER', 'UPDATE_USER', 'CHANGE_USER_STATUS', 'RESET_USER_PASSWORD'], array_column($r['data'], 'action'))) === 4);
$r = $logs->search(['user_id' => (string) BQL, 'action' => 'UPDATE_SYSTEM_PARAMETER'], 1);
check($G, 'lọc người thực hiện + action', $r['total'] >= 5 && array_unique(array_column($r['data'], 'user_id')) === [BQL]);
$r = $logs->search(['ip_address' => '10.1.2.3', 'action' => 'CREATE_USER'], 1);
check($G, 'lọc IP', $r['total'] === 1);
check($G, 'lọc IP khác -> 0 bản ghi', $logs->search(['ip_address' => '10.9.9.9'], 1)['total'] === 0);
$today = date('Y-m-d');
check($G, 'lọc khoảng ngày hôm nay có dữ liệu; ngày mai -> 0', $logs->search(['date_from' => $today, 'date_to' => $today], 1)['total'] > 0
    && $logs->search(['date_from' => date('Y-m-d', strtotime('+1 day'))], 1)['total'] === 0);
expectError($G, 'từ ngày > đến ngày -> lỗi', 'date_to', fn () => $logs->search(['date_from' => '2026-05-01', 'date_to' => '2026-01-01'], 1), 'nhỏ hơn hoặc bằng');
expectError($G, 'ngày sai định dạng (2026-02-30) -> lỗi', 'date_from', fn () => $logs->search(['date_from' => '2026-02-30'], 1));
expectError($G, 'IP sai -> lỗi', 'ip_address', fn () => $logs->search(['ip_address' => "1.2.3.4' OR 1=1"], 1));
expectError($G, 'người thực hiện không phải số -> lỗi', 'user_id', fn () => $logs->search(['user_id' => '1 OR 1=1'], 1));
$r = $logs->search(['entity_id' => "' OR '1'='1"], 1);
check($G, 'entity_id kiểu SQL injection -> 0 bản ghi (prepared)', $r['total'] === 0);
$cnt = (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs');
$pdo->exec("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, created_at) VALUES
    (1, 'TEST_MASK', 'test', '1', '{\"password\":\"P@ss1\",\"nested\":{\"csrf_token\":\"c1\",\"api_key\":\"k1\"}}', '{\"password_hash\":\"h1\",\"session_id\":\"s1\",\"version_token\":\"v1\",\"secret\":\"z1\",\"note\":\"ok\"}', '1.1.1.1', NOW())");
$m = $logs->search(['action' => 'TEST_MASK'], 1)['data'][0];
$flat = json_encode([$m['old_value'], $m['new_value']]);
check($G, 'che khóa nhạy cảm (password, csrf_token, api_key, password_hash, session_id, secret) -> ***', !preg_match('/P@ss1|c1|k1|h1|s1|z1/', $flat) && substr_count($flat, '***') === 6, $flat);
check($G, 'giữ khóa không nhạy cảm (version_token, note)', $m['new_value']['version_token'] === 'v1' && $m['new_value']['note'] === 'ok');
check($G, 'find() chi tiết cũng che', $logs->find((int) $m['id'])['old_value']['password'] === '***');
for ($i = 0; $i < 60; $i++) {
    $pdo->exec("INSERT INTO audit_logs (user_id, action, entity_type, created_at) VALUES (1, 'TEST_PAGE', 'test', NOW())");
}
$p1 = $logs->search(['action' => 'TEST_PAGE'], 1);
$p2 = $logs->search(['action' => 'TEST_PAGE'], 2);
check($G, 'phân trang 50 / trang (60 bản ghi -> 50 + 10), mới nhất trước', count($p1['data']) === 50 && count($p2['data']) === 10 && $p1['last_page'] === 2 && $p1['data'][0]['id'] > $p1['data'][1]['id']);
check($G, 'tra cứu chỉ đọc: số bản ghi audit không đổi sau các lần search', (int) q($pdo, 'SELECT COUNT(*) FROM audit_logs') === $cnt + 61);

// =========================================================== ĐỒNG THỜI
$G = 'CONCURRENCY';
$pdo->exec("UPDATE users SET status = 'hoat_dong', failed_login_count = 0, locked_until = NULL WHERE id = " . (int) $newId);
$t = $tok((int) $newId);
$out = race($testDb, [[BQL, 'bql', 'update', $newId, 'Người A', 'ke_toan', $t], [BQL, 'bql', 'update', $newId, 'Người B', 'ky_thuat', $t]]);
$fn = userRow($pdo, (int) $newId)['full_name'];
check($G, '2 người sửa cùng tài khoản (cùng token) -> đúng 1 thành công, người sau bị từ chối', okCount($out) === 1 && in_array($fn, ['Người A', 'Người B'], true), json_encode($out, JSON_UNESCAPED_UNICODE));
$t = $tok((int) $newId);
$out = race($testDb, [[BQL, 'bql', 'status', $newId, 'deactivate', $t], [BQL, 'bql', 'status', $newId, 'deactivate', $t]]);
check($G, 'khóa (vô hiệu hóa) cùng tài khoản đồng thời -> 1 thành công, 1 audit', okCount($out) === 1 && userRow($pdo, (int) $newId)['status'] === 'ngung'
    && (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE action = 'CHANGE_USER_STATUS' AND entity_id = ? AND new_value LIKE '%deactivate%' AND ip_address = 'fn01-concurrency-worker'", [$newId]) === 1, json_encode($out, JSON_UNESCAPED_UNICODE));
$t = $tok((int) $newId);
$out = race($testDb, [[BQL, 'bql', 'reset', $newId, 'Race@2026A', $t], [BQL, 'bql', 'reset', $newId, 'Race@2026B', $t]]);
$h = userRow($pdo, (int) $newId)['password_hash'];
check($G, 'đặt lại mật khẩu đồng thời -> 1 thành công, mật khẩu cuối khớp đúng bên thắng', okCount($out) === 1
    && (password_verify('Race@2026A', $h) xor password_verify('Race@2026B', $h)), json_encode($out, JSON_UNESCAPED_UNICODE));
$pt = $pTok('session_timeout_minutes');
$out = race($testDb, [[BQL, 'bql', 'param', $ids['session_timeout_minutes'], '60', $pt], [BQL, 'bql', 'param', $ids['session_timeout_minutes'], '90', $pt]]);
check($G, '2 người sửa cùng tham số -> 1 thành công, 1 audit', okCount($out) === 1 && in_array(q($pdo, "SELECT config_value FROM system_configs WHERE config_key = 'session_timeout_minutes'"), ['60', '90'], true)
    && (int) q($pdo, "SELECT COUNT(*) FROM audit_logs WHERE action = 'UPDATE_SYSTEM_PARAMETER' AND ip_address = 'fn01-concurrency-worker'") === 1, json_encode($out, JSON_UNESCAPED_UNICODE));
// 2 BQL vô hiệu hoá lẫn nhau cùng lúc -> luôn còn >= 1 BQL hoạt động
$bql2 = $users->create(['username' => 'bql.hai', 'full_name' => 'BQL Hai', 'role' => 'bql'], PW, PW);
$out = race($testDb, [[BQL, 'bql', 'status', $bql2, 'deactivate', $tok((int) $bql2)], [$bql2, 'bql', 'status', BQL, 'deactivate', $tok(BQL)]]);
check($G, '2 BQL vô hiệu hóa nhau cùng lúc -> đúng 1 thành công, còn 1 BQL hoạt động, không deadlock', okCount($out) === 1
    && (int) q($pdo, "SELECT COUNT(*) FROM users WHERE role = 'bql' AND status = 'hoat_dong'") === 1 && !str_contains(json_encode($out), 'EXCEPTION'), json_encode($out, JSON_UNESCAPED_UNICODE));
$pdo->exec("UPDATE users SET status = 'hoat_dong' WHERE id = " . BQL);
$t1 = $tok((int) $newId);
$users->changeStatus((int) $newId, 'activate', $t1);
$out = race($testDb, [[BQL, 'bql', 'update', $bql2, 'BQL Hai', 'ke_toan', $tok((int) $bql2)], [$bql2, 'bql', 'update', BQL, 'BQL Một', 'ky_thuat', $tok(BQL)]]);
check($G, '2 BQL hạ vai trò nhau cùng lúc -> còn >= 1 BQL hoạt động', (int) q($pdo, "SELECT COUNT(*) FROM users WHERE role = 'bql' AND status = 'hoat_dong'") >= 1 && !str_contains(json_encode($out), 'EXCEPTION'), json_encode($out, JSON_UNESCAPED_UNICODE));

// =========================================================== GENERAL
$G = 'GENERAL';
foreach (['CREATE_USER', 'UPDATE_USER', 'CHANGE_USER_STATUS', 'RESET_USER_PASSWORD', 'UPDATE_SYSTEM_PARAMETER'] as $action) {
    check($G, "có audit {$action} và tra cứu được qua AuditLogService", $logs->search(['action' => $action], 1)['total'] > 0);
}
check($G, 'không có tài khoản nào bị xóa cứng', (int) q($pdo, 'SELECT COUNT(*) FROM users') === 5);
$sources = implode("\n", array_map('file_get_contents', glob(dirname(__DIR__) . '/app/{Controllers,Services}/{User,AuditLog,SystemParameter}*.php', GLOB_BRACE)));
check($G, 'không có magic-role check trong Controller / Service mới (chỉ RBAC qua route)', !preg_match("/user_role'\\)\\s*===|\\['role'\\]\\s*===\\s*'(bql|ky_thuat|ke_toan|admin)'/", $sources));
check($G, 'không có SQL DELETE / UPDATE audit_logs trong code ứng dụng (log chỉ đọc)', !preg_match('/(DELETE\s+FROM|UPDATE)\s+audit_logs/i', implode("\n", array_map('file_get_contents', glob(dirname(__DIR__) . '/app/*/*.php')))));

// ===========================================================
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
echo "\n{$pass}/{$total} PASS (service test, db={$testDb})\n";
exit($pass === $total ? 0 : 1);
