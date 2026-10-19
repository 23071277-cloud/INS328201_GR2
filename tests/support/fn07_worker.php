<?php

declare(strict_types=1);

/**
 * Worker cho test đồng thời FN-07: chờ mốc thời gian chung rồi chạy 1 thao tác.
 *   evaluate <period_id> <rule_id|0> | manual <apartment_id> <meter_id> <period_id> <utility>
 *   review <alert_id> <priority> <token> | request <alert_id> <assignee> <token> | result <alert_id>
 *   close <alert_id> <token> | reopen <alert_id> <token>
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
$_SERVER['REMOTE_ADDR'] = 'fn07-concurrency-worker';
\App\Core\Database::connection();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $wf = new \App\Services\AlertWorkflowService();
    $vf = new \App\Services\AlertVerificationService();
    switch ($action) {
        case 'evaluate':
            $r = (new \App\Services\AlertEvaluationService())->evaluatePeriod((int) $rest[0], (int) $rest[1] ?: null);
            $message = 'created ' . count($r['created']) . ' dup ' . $r['duplicates'];
            break;
        case 'manual':
            $r = (new \App\Services\AlertService())->createManual(['apartment_id' => $rest[0], 'meter_id' => $rest[1], 'billing_period_id' => $rest[2], 'utility_type' => $rest[3], 'description' => 'race manual', 'priority' => 'cao']);
            $message = 'manual ' . $r['id'];
            break;
        case 'review':
            $wf->review((int) $rest[0], $rest[1], 'race', $rest[2]);
            $message = 'reviewed';
            break;
        case 'request':
            $vf->request((int) $rest[0], 'race request', $rest[1], $rest[2]);
            $message = 'requested';
            break;
        case 'result':
            $vf->submitResult((int) $rest[0], 'canh_bao_dung', '', 'race');
            $message = 'result';
            break;
        case 'close':
            $wf->close((int) $rest[0], 'race close', $rest[1]);
            $message = 'closed';
            break;
        case 'reopen':
            $wf->reopen((int) $rest[0], 'race reopen', $rest[1]);
            $message = 'reopened';
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
