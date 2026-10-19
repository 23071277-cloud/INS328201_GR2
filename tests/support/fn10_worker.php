<?php

declare(strict_types=1);

/**
 * Worker cho test đồng thời FN-10.03 (STEP 25): chờ mốc thời gian chung rồi chạy 1 thao tác, driver email "array" (không gửi thật).
 *   send <charge_id> <resident_id> | retry <notification_id>
 * In JSON {"ok":bool,"message":string}.
 */

$args = array_slice($argv, 1);
[$db, $startAt, $actor, $role, $action] = array_slice($args, 0, 5);
$rest = array_slice($args, 5);
if ($db === '' || $db === 'energy_dss') {
    fwrite(STDERR, "Cần DB tạm.\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/bootstrap.php';
$_ENV['DB_DATABASE'] = $db;
restore_error_handler();
restore_exception_handler();
App\Core\Config::set('mail.driver', 'array');
$_SESSION = ['user_id' => (int) $actor, 'user_role' => $role];
$_SERVER['REMOTE_ADDR'] = 'fn10-concurrency-worker';
\App\Core\Database::connection();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $svc = new \App\Services\EmailNotificationService();
    $r = $action === 'send' ? $svc->sendBillNotice((int) $rest[0], $rest[1]) : $svc->retry((int) $rest[0]);
    echo json_encode(['ok' => $r['status'] === 'sent', 'message' => $r['status']], JSON_UNESCAPED_UNICODE);
} catch (\App\Core\ValidationException $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
