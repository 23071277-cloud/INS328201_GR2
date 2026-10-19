<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\DashboardService;

/**
 * FN-09.01 Dashboard quyết định (BQL; Kế toán, Kỹ thuật xem phần được phép - 2.3). CHỈ ĐỌC.
 * Vai trò không có dashboard.decision (hiện không có - 3 vai trò đều có) thấy trang chào.
 */
class DashboardController extends BaseController
{
    public function index(): void
    {
        if (!can('dashboard.decision')) {
            $this->view('dashboard.index', ['title' => t('page.dashboard_basic')]);

            return;
        }
        $filters = $this->filters(['period_id', 'building_id', 'zone_id', 'utility_type']);
        $service = new DashboardService();
        $data = $service->build($filters, auth_user());

        $this->view('dashboard.decision', [
            'title' => t('page.dashboard'),
            'd' => $data,
            'options' => $service->filterOptions((int) $data['filters']['building_id'] ?: null),
        ]);
    }
}
