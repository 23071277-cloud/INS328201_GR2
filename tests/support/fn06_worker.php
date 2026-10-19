<?php

declare(strict_types=1);

/**
 * Worker cho test đồng thời FN-06: chờ mốc thời gian chung rồi chạy 1 thao tác.
 *   php fn06_worker.php <db_tạm> <start_at> <actor_id> <role> target_create <scope_type> <scope_id> <utility> <period_id> <value>
 *   php fn06_worker.php <db_tạm> <start_at> <actor_id> <role> target_update <target_id> <value> <token>
 *   php fn06_worker.php <db_tạm> <start_at> <actor_id> <role> baseline_create <scope_type> <scope_id> <utility> <manual_value> <effective_date>
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
$_SERVER['REMOTE_ADDR'] = 'fn06-concurrency-worker';
\App\Core\Database::connection();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    switch ($action) {
        case 'target_create':
            $id = (new \App\Services\ConsumptionTargetService())->create(['scope_type' => $rest[0], 'scope_id' => $rest[1], 'utility_type' => $rest[2], 'billing_period_id' => $rest[3], 'target_value' => $rest[4]]);
            $message = 'target ' . $id;
            break;
        case 'target_update':
            (new \App\Services\ConsumptionTargetService())->update((int) $rest[0], ['target_value' => $rest[1], 'version_token' => $rest[2]]);
            $message = 'updated';
            break;
        case 'baseline_create':
            $id = (new \App\Services\BaselineService())->create(['scope_type' => $rest[0], 'scope_id' => $rest[1], 'utility_type' => $rest[2], 'method' => 'nhap_tay', 'manual_value' => $rest[3], 'effective_date' => $rest[4]]);
            $message = 'baseline ' . $id;
            break;
        default:
            throw new RuntimeException('unknown action');
    }
    echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
} catch (\App\Core\ValidationException $e) {
    echo json_encode(['ok' => false, 'message' => implode(' | ', array_merge(...array_values($e->errors())))], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
