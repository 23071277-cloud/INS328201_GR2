<?php

declare(strict_types=1);

/**
 * Worker cho test đồng thời FN-05: chờ tới mốc thời gian chung rồi chạy 1 thao tác.
 *   php fn05_worker.php <db_tạm> <start_at> <actor_id> <role> calc    <period_id> <apartment_id>
 *   php fn05_worker.php <db_tạm> <start_at> <actor_id> <role> edit    <reading_id> <current> <token>
 *   php fn05_worker.php <db_tạm> <start_at> <actor_id> <role> tariff  <effective_date> <tier1_price>
 *   php fn05_worker.php <db_tạm> <start_at> <actor_id> <role> confirm <period_id> <charge_id> <token>
 *   php fn05_worker.php <db_tạm> <start_at> <actor_id> <role> lock    <period_id>
 *   php fn05_worker.php <db_tạm> <start_at> <actor_id> <role> reopen  <period_id> <reason>
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
$_SERVER['REMOTE_ADDR'] = 'fn05-concurrency-worker';
\App\Core\Database::connection();

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    switch ($action) {
        case 'calc':
            $r = (new \App\Services\BillingCalculationService())->calculateApartment((int) $rest[0], (int) $rest[1]);
            $message = $r['action'];
            break;
        case 'edit':
            (new \App\Services\MeterReadingService())->update((int) $rest[0], ['current_reading' => $rest[1], 'note' => 'race edit ' . $rest[1], 'version_token' => $rest[2]]);
            $message = 'edited';
            break;
        case 'tariff':
            $tiers = [
                ['tier_from' => '0', 'tier_to' => '50', 'unit_price' => $rest[1]],
                ['tier_from' => '50', 'tier_to' => '100', 'unit_price' => '1866'],
                ['tier_from' => '100', 'tier_to' => '200', 'unit_price' => '2167'],
                ['tier_from' => '200', 'tier_to' => '300', 'unit_price' => '2729'],
                ['tier_from' => '300', 'tier_to' => '400', 'unit_price' => '3050'],
                ['tier_from' => '400', 'tier_to' => '', 'unit_price' => '3151'],
            ];
            $id = (new \App\Services\TariffService())->create(['utility_type' => 'dien', 'usage_type' => 'sinh_hoat', 'vat_percent' => '8', 'business_unit_price' => '', 'effective_date' => $rest[0], 'tiers' => $tiers]);
            $message = 'tariff ' . $id;
            break;
        case 'confirm':
            $r = (new \App\Services\BillingConfirmationService())->confirm((int) $rest[0], [(int) $rest[1] => $rest[2]]);
            if ($r['done'] === []) {
                echo json_encode(['ok' => false, 'message' => (string) reset($r['skipped'])], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $message = 'confirmed';
            break;
        case 'lock':
            (new \App\Services\PeriodClosingService())->lock((int) $rest[0]);
            $message = 'locked';
            break;
        case 'reopen':
            (new \App\Services\PeriodClosingService())->reopen((int) $rest[0], $rest[1]);
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
