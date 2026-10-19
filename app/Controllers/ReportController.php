<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuditLogger;
use App\Core\BaseController;
use App\Services\UtilityReportService;

/** FN-09.03 Báo cáo tiện ích toàn tòa (BQL, Kế toán: xem + xuất CSV; Kỹ thuật: xem giới hạn, không xuất - 2.3). */
class ReportController extends BaseController
{
    public function utility(): void
    {
        $service = new UtilityReportService();
        $report = $service->build($this->reportFilters(), $this->visibility());

        $this->view('reports.utility', [
            'title' => t('page.reports'),
            'r' => $report,
            'options' => $service->options((int) $report['filters']['building_id'] ?: null),
        ]);
    }

    /** CSV UTF-8 (U7). Ghi audit xuất dữ liệu ([ĐX] FN-09.03 mục 13) - không đổi dữ liệu nghiệp vụ. */
    public function export(): void
    {
        $service = new UtilityReportService();
        $report = $service->build($this->reportFilters(), $this->visibility());
        $csv = $service->csv($report);
        AuditLogger::log('EXPORT_UTILITY_REPORT', 'billing_period', (string) ($report['period']['id'] ?? ''), null,
            ['filters' => $report['filters'], 'rows' => count($report['rows']), 'with_names' => $report['show']['names']]);

        $code = str_replace('/', '-', (string) ($report['period']['period_code'] ?? 'khong-co-ky'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bao-cao-tien-ich-' . $code . '.csv"');
        header('X-Content-Type-Options: nosniff');
        echo $csv;
        exit;
    }

    private function reportFilters(): array
    {
        return $this->filters(['period_id', 'building_id', 'zone_id', 'apartment', 'utility_type']);
    }

    /** Kỹ thuật: không cột tiền (không có billing.view); họ tên chủ hộ chỉ khi có quyền xem cư dân (BR-36). */
    private function visibility(): array
    {
        return ['cost' => can('billing.view'), 'names' => can('residents.view')];
    }
}
