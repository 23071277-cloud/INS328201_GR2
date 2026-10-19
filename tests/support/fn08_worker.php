<?php

declare(strict_types=1);

/**
 * Worker cho test đồng thời FN-08: chờ mốc thời gian chung rồi chạy 1 thao tác.
 *   generate <alert_id> | status <rule_id> <bat|tat> <token> | update <rule_id> <text> <token>
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
$_SERVER['REMOTE_ADDR'] = 'fn08-concurrency-worker';
\App\Core\Database::connection();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    switch ($action) {
        case 'generate':
            $r = (new \App\Services\RecommendationService())->generateForAlert((int) $rest[0]);
            $message = 'created ' . count($r['created']) . ' dup ' . $r['duplicates'];
            break;
        case 'status':
            (new \App\Services\RecommendationRuleService())->setStatus((int) $rest[0], $rest[1], $rest[2]);
            $message = 'status';
            break;
        case 'update':
            $svc = new \App\Services\RecommendationRuleService();
            $rule = $svc->find((int) $rest[0]);
            $svc->update((int) $rest[0], ['name' => $rule['name'], 'condition_type' => $rule['condition_type'], 'n_periods' => (string) ($rule['params']['n_periods'] ?? ''),
                'utility_type' => (string) ($rule['params']['utility_type'] ?? ''), 'recommendation_text' => $rest[1], 'version_token' => $rest[2]]);
            $message = 'updated';
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
