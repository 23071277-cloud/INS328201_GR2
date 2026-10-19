<?php

declare(strict_types=1);

/**
 * Dấu vân tay dữ liệu DB thật (chỉ đọc) - dùng để chứng minh bộ test không sửa / xoá dữ liệu của người dùng.
 *   php tests/support/user_data_fingerprint.php > before.json   ...chạy test...   php tests/support/user_data_fingerprint.php > after.json
 * Mỗi bảng (trừ audit_logs - chỉ ghi thêm theo BR-04): danh sách id + md5 nội dung từng dòng, BỎ cột biến động
 * (updated_at, thông tin đăng nhập / khoá tạm của tài khoản test đăng nhập). So sánh: php ... --diff before.json after.json
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

const VOLATILE = ['updated_at', 'last_login_at', 'failed_login_count', 'failed_attempts', 'locked_until', 'session_version'];

if (($argv[1] ?? '') === '--diff') {
    $a = json_decode((string) file_get_contents($argv[2]), true);
    $b = json_decode((string) file_get_contents($argv[3]), true);
    $changes = [];
    foreach ($a as $table => $rows) {
        $after = $b[$table] ?? [];
        $removed = array_diff_key($rows, $after);
        $changed = array_keys(array_filter(array_intersect_key($rows, $after), static fn ($h, $id) => $after[$id] !== $h, ARRAY_FILTER_USE_BOTH));
        $added = array_diff_key($after, $rows);
        if ($removed || $changed || $added) {
            $changes[$table] = ['removed' => array_keys($removed), 'changed' => $changed, 'added' => array_keys($added)];
        }
    }
    echo json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit($changes === [] ? 0 : 1);
}

$pdo = App\Core\Database::connection();
$out = [];
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    if ($table === 'audit_logs') {
        continue;
    }
    $cols = array_values(array_diff($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN), VOLATILE));
    $list = implode(',', array_map(static fn (string $c): string => "`{$c}`", $cols));
    $rows = [];
    foreach ($pdo->query("SELECT {$list} FROM `{$table}` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(string) reset($row)] = md5(json_encode($row, JSON_UNESCAPED_UNICODE));
    }
    $out[$table] = $rows;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
