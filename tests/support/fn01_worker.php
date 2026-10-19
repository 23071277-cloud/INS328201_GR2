<?php

declare(strict_types=1);

/**
 * Worker cho test đồng thời FN-01 (STEP 15): chờ mốc thời gian chung rồi chạy 1 thao tác.
 *   update <user_id> <full_name> <role> <token> | status <user_id> <action> <token>
 *   reset <user_id> <password> <token> | param <config_id> <value> <token>
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
$_SESSION = ['user_id' => (int) $actor, 'user_role' => $role];
$_SERVER['REMOTE_ADDR'] = 'fn01-concurrency-worker';
\App\Core\Database::connection();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $users = new \App\Services\UserService();
    switch ($action) {
        case 'update':
            $users->update((int) $rest[0], ['full_name' => $rest[1], 'role' => $rest[2]], $rest[3]);
            break;
        case 'status':
            $users->changeStatus((int) $rest[0], $rest[1], $rest[2]);
            break;
        case 'reset':
            $users->resetPassword((int) $rest[0], $rest[1], $rest[1], $rest[2]);
            break;
        case 'param':
            (new \App\Services\SystemParameterService())->update((int) $rest[0], $rest[1], $rest[2]);
            break;
        default:
            throw new RuntimeException('unknown action');
    }
    echo json_encode(['ok' => true, 'message' => $action], JSON_UNESCAPED_UNICODE);
} catch (\App\Core\ValidationException $e) {
    echo json_encode(['ok' => false, 'message' => implode(' | ', array_merge(...array_values($e->errors())))], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
