<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\BillingPeriodService;
use App\Services\CostAllocationService;

/** FN-05.05 Cost Allocation Summary (BQL, Kế toán xem). */
class CostAllocationController extends BaseController
{
    public function index(string $id): void
    {
        $period = (new BillingPeriodService())->find($this->routeId($id)) ?? $this->notFound();
        $confirmedOnly = $this->filters(['scope'])['scope'] !== 'all';

        $this->view('billing.allocation', [
            'title' => 'Tổng hợp chi phí — kỳ ' . $period['period_code'],
            'period' => $period,
            'confirmedOnly' => $confirmedOnly,
            'summary' => (new CostAllocationService())->summary((int) $period['id'], $confirmedOnly),
        ]);
    }
}
