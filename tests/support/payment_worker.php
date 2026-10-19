<?php

declare(strict_types=1);

/**
 * Worker cho test đồng thời Payment module: chờ tới mốc thời gian chung rồi gọi PaymentReconciliationService::receive().
 *   php tests/support/payment_worker.php <db_tạm> <transaction_id> <amount> <content> <start_at_unix_float>
 * In ra JSON {"ok":bool, "match_status":?, "duplicate":?, "became_paid":?, "message":?}. Email: driver array (không gửi thật).
 */

[, $db, $txn, $amount, $content, $startAt] = $argv + [null, '', '', '0', '', '0'];
if ($db === '' || in_array($db, ['energy_dss', 'energy_dss_v2'], true)) {
    fwrite(STDERR, "Cần DB tạm.\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/bootstrap.php';
$_ENV['DB_DATABASE'] = $db;
restore_error_handler();
restore_exception_handler();
\App\Core\Config::set('mail.driver', 'array');
$_SERVER['REMOTE_ADDR'] = 'payment-concurrency-worker';

\App\Core\Database::connection(); // kết nối sẵn để các worker xuất phát công bằng
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $r = (new \App\Services\PaymentReconciliationService())->receive(['transaction_id' => $txn, 'amount' => $amount, 'content' => $content]);
    echo json_encode(['ok' => true, 'match_status' => $r['match_status'], 'duplicate' => $r['duplicate'], 'became_paid' => $r['became_paid'], 'emails' => count($r['emails'])]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
