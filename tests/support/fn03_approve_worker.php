<?php

declare(strict_types=1);

/**
 * Worker cho test đồng thời FN-03.04: chờ tới mốc thời gian chung rồi gọi approve().
 *   php tests/support/fn03_approve_worker.php <db_tạm> <proposal_id> <actor_id> <start_at_unix_float>
 * In ra JSON {"ok":bool,"message":string}.
 */

[, $db, $proposalId, $actorId, $startAt] = $argv + [null, '', '0', '1', '0'];
if ($db === '' || $db === 'energy_dss') {
    fwrite(STDERR, "Cần DB tạm.\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/bootstrap.php';
$_ENV['DB_DATABASE'] = $db;
restore_error_handler();
restore_exception_handler();
$_SESSION = ['user_id' => (int) $actorId, 'user_role' => 'bql'];
$_SERVER['REMOTE_ADDR'] = 'fn03-concurrency-worker';

\App\Core\Database::connection(); // kết nối sẵn để 2 worker xuất phát công bằng
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $tariffId = (new \App\Services\TariffProposalService())->approve((int) $proposalId);
    echo json_encode(['ok' => true, 'message' => "tariff {$tariffId}"], JSON_UNESCAPED_UNICODE);
} catch (\App\Core\ValidationException $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
