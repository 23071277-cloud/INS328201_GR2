<?php

declare(strict_types=1);

/**
 * STEP 4C - so sánh HÀNH VI ứng dụng giữa energy_dss (schema v1) và energy_dss_v2 (schema v2) - CHỈ ĐỌC.
 *   DB_DATABASE=energy_dss    php tests/v2_behaviour_compare.php dump v1.json
 *   DB_DATABASE=energy_dss_v2 php tests/v2_behaviour_compare.php dump v2.json
 *   php tests/v2_behaviour_compare.php diff v1.json v2.json
 * dump gọi các Service / Repository ở chế độ đọc (list / detail / compute / overview / report…) - không ghi DB.
 * Chuẩn hoá: id mới của phí cố định / sự kiện cảnh báo (đánh số lại khi migrate) được thay bằng khoá nghiệp vụ.
 */

[$_, $mode] = $argv + [null, ''];

if ($mode === 'diff') {
    $a = json_decode((string) file_get_contents($argv[2]), true);
    $b = json_decode((string) file_get_contents($argv[3]), true);
    $diffs = [];
    $walk = static function ($x, $y, string $path) use (&$walk, &$diffs): void {
        if (is_array($x) && is_array($y)) {
            foreach (array_unique(array_merge(array_keys($x), array_keys($y))) as $k) {
                if (!array_key_exists($k, $x) || !array_key_exists($k, $y)) {
                    $diffs[] = "{$path}.{$k}: " . (array_key_exists($k, $x) ? 'chỉ có ở v1' : 'chỉ có ở v2');
                    continue;
                }
                $walk($x[$k], $y[$k], "{$path}.{$k}");
            }

            return;
        }
        if ($x !== $y) {
            $diffs[] = "{$path}: v1=" . json_encode($x, JSON_UNESCAPED_UNICODE) . ' v2=' . json_encode($y, JSON_UNESCAPED_UNICODE);
        }
    };
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $section) {
        $before = count($diffs);
        $walk($a[$section] ?? null, $b[$section] ?? null, $section);
        printf("%s %-28s %s\n", count($diffs) === $before ? 'SAME' : 'DIFF', $section, count($diffs) === $before ? '' : (count($diffs) - $before) . ' khác biệt');
    }
    foreach (array_slice($diffs, 0, 40) as $d) {
        echo "  - {$d}\n";
    }
    echo "\n" . ($diffs === [] ? 'BEHAVIOUR IDENTICAL' : count($diffs) . ' KHÁC BIỆT') . "\n";
    exit($diffs === [] ? 0 : 1);
}

if ($mode !== 'dump' || !isset($argv[2])) {
    fwrite(STDERR, "Dùng: dump <out.json> | diff <v1.json> <v2.json>\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();
$_SESSION = ['user_id' => 1, 'user_role' => 'bql', 'username' => 'compare', 'full_name' => 'compare'];

use App\Core\Database;
use App\Core\Schema;
use App\Services\AlertService;
use App\Services\BillingCalculationService;
use App\Services\BillNoticeService;
use App\Services\EmailNotificationService;
use App\Services\FixedFeeService;
use App\Services\PeriodBillEmailService;
use App\Services\PeriodClosingService;
use App\Services\RecommendationRuleService;
use App\Services\RecommendationService;
use App\Services\TariffProposalService;
use App\Services\TariffService;

$pdo = Database::connection();
$db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$feeKey = static fn (array $r): string => $r['fee_type'] . '@' . $r['effective_date'];
$strip = static function (array $rows, array $drop): array {
    return array_map(static fn (array $r): array => array_diff_key($r, array_flip($drop)), $rows);
};
$out = ['_meta' => null];

// Giá
$tariffService = new TariffService();
$tariffs = $tariffService->list(['utility_type' => '', 'usage_type' => '', 'lifecycle' => '', 'effective_from' => '', 'effective_to' => ''], 1)['data'] ?? [];
$out['pricing.tariffs'] = $tariffs;
foreach ($tariffs as $t) {
    $d = $tariffService->detail((int) $t['id']);
    $out['pricing.tariff_detail'][$t['id']] = ['tiers' => $d['tiers'] ?? null, 'versions' => array_column($d['versions'] ?? [], 'id'), 'proposals' => $d['proposals'] ?? null,
        'token' => $tariffService->versionToken($d, $d['tiers'] ?? [])];
}
$fees = (new FixedFeeService())->overview();
$out['pricing.fixed_fees'] = [];
foreach (($fees['rows'] ?? $fees) as $group => $rows) {
    foreach ((array) $rows as $r) {
        if (is_array($r) && isset($r['fee_type'])) {
            $out['pricing.fixed_fees'][$feeKey($r)] = array_diff_key($r, ['id' => 1]);
        }
    }
}
ksort($out['pricing.fixed_fees']);
$out['pricing.proposals'] = (new TariffProposalService())->list(['status' => '', 'utility_type' => ''], 1)['data'] ?? [];

// Tính phí: tính lại (compute - không ghi) mọi căn có bản tính, so với số đã lưu
$billing = new BillingCalculationService();
foreach ($pdo->query('SELECT c.id, c.billing_period_id, c.apartment_id, c.old_debt_amount FROM apartment_charges c ORDER BY c.id')->fetchAll() as $c) {
    $period = $pdo->query('SELECT * FROM billing_periods WHERE id = ' . (int) $c['billing_period_id'])->fetch();
    $apartment = $pdo->query('SELECT a.*, b.code AS building_code FROM apartments a JOIN buildings b ON b.id = a.building_id WHERE a.id = ' . (int) $c['apartment_id'])->fetch();
    try {
        $r = $billing->compute($period, $apartment, (int) round((float) $c['old_debt_amount'] * 100));
        $out['billing.compute'][$c['id']] = $r;
    } catch (Throwable $e) {
        $out['billing.compute'][$c['id']] = 'ERROR: ' . $e->getMessage();
    }
    $out['billing.detail'][$c['id']] = $billing->detail((int) $c['id']);
}
foreach ($pdo->query('SELECT id FROM billing_periods ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $pid) {
    $out['billing.overview'][$pid] = $billing->overview((int) $pid);
    $out['period.lock_blockers'][$pid] = (new PeriodClosingService())->lockBlockers((int) $pid);
    $out['period.bill_email_report'][$pid] = (new PeriodBillEmailService())->report((int) $pid)['count'];
    try {
        $out['bill.notices'][$pid] = (new BillNoticeService())->forPeriod((int) $pid);
    } catch (Throwable $e) {
        $out['bill.notices'][$pid] = 'ERROR: ' . $e->getMessage();
    }
}

// Cảnh báo + J-16 + khuyến nghị
$alerts = new AlertService();
foreach ($pdo->query('SELECT id FROM alerts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $aid) {
    $d = $alerts->detail((int) $aid);
    if (isset($d['history'])) {
        $d['history'] = $strip($d['history'], ['id']);
    }
    if (isset($d['contacts'])) {
        $d['contacts'] = $strip($d['contacts'], ['id']);
    }
    $out['alerts.detail'][$aid] = $d;
    $out['recommendations.for_alert'][$aid] = (new RecommendationService())->forAlert((int) $aid);
}
$out['recommendation_rules'] = (new RecommendationRuleService())->list(['status' => '', 'condition_type' => '']);

// Email
$mail = new EmailNotificationService();
$out['email.search'] = $mail->search([], 1);
foreach ($pdo->query('SELECT id FROM apartment_charges ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $out['email.history_for_charge'][$cid] = $mail->historyForCharge((int) $cid);
}

$out['_meta'] = ['note' => 'db và schema khác nhau là mong đợi'];
$meta = ['db' => $db, 'schema' => Schema::isV2() ? 'v2' : 'v1'];
file_put_contents($argv[2], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($meta) . ' -> ' . $argv[2] . ' (' . count($out) . " mục)\n";
