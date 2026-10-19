<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\BillingPeriodService;
use App\Services\ConsumptionComparisonService;

/** FN-06.02 So sánh tiêu thụ với kỳ trước / baseline (chỉ đọc, không sinh cảnh báo). */
class ComparisonController extends BaseController
{
    public function index(): void
    {
        $f = $this->filters(['period_id', 'scope_type', 'utility_type']);
        $periods = (new BillingPeriodService())->options();
        $periodId = ctype_digit($f['period_id']) ? (int) $f['period_id'] : (int) ($periods[0]['id'] ?? 0);
        $scopeType = array_key_exists($f['scope_type'], config('analytics.scope_type')) ? $f['scope_type'] : 'apartment';
        $utility = array_key_exists($f['utility_type'], config('master_data.utility_type')) ? $f['utility_type'] : 'dien';

        $this->view('comparisons.index', [
            'title' => t('page.comparisons'),
            'periods' => $periods,
            'filters' => ['period_id' => (string) $periodId, 'scope_type' => $scopeType, 'utility_type' => $utility],
            'table' => $periodId > 0 ? (new ConsumptionComparisonService())->table($periodId, $scopeType, $utility) : null,
        ]);
    }
}
