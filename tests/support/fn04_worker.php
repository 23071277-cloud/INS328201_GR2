<?php

declare(strict_types=1);

/**
 * Worker cho test đồng thời FN-04: chờ tới mốc thời gian chung rồi chạy 1 thao tác.
 *   php fn04_worker.php <db_tạm> <start_at> <actor_id> <role> manual  <period_id> <meter_id> <current>
 *   php fn04_worker.php <db_tạm> <start_at> <actor_id> <role> approve <period_id> <reading_id> <token>
 *   php fn04_worker.php <db_tạm> <start_at> <actor_id> <role> edit    <reading_id> <current> <token>
 *   php fn04_worker.php <db_tạm> <start_at> <actor_id> <role> confirm <batch_id>
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
$_SERVER['REMOTE_ADDR'] = 'fn04-concurrency-worker';
\App\Core\Database::connection();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    switch ($action) {
        case 'manual':
            $r = (new \App\Services\MeterReadingService())->create((int) $rest[0], (int) $rest[1], ['current_reading' => $rest[2], 'note' => 'race']);
            $message = 'reading ' . $r['id'];
            break;
        case 'approve':
            $r = (new \App\Services\ReadingApprovalService())->approve((int) $rest[0], [(int) $rest[1] => $rest[2]]);
            if ($r['approved'] === []) {
                echo json_encode(['ok' => false, 'message' => (string) reset($r['skipped'])], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $message = 'approved';
            break;
        case 'edit':
            (new \App\Services\MeterReadingService())->update((int) $rest[0], ['current_reading' => $rest[1], 'note' => 'race edit ' . $rest[1], 'version_token' => $rest[2]]);
            $message = 'edited';
            break;
        case 'confirm':
            $r = (new \App\Services\ReadingImportService())->confirm((int) $rest[0], true);
            $message = "saved {$r['saved']}";
            break;
        default:
            throw new RuntimeException('unknown action');
    }
    echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
} catch (\App\Core\ValidationException $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
