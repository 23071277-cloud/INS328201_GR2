<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\BillingPeriodService;
use App\Services\ReadingApprovalService;

/** FN-04.08 Phê duyệt dữ liệu chỉ số (BQL) - từng bản hoặc theo lô. "Trả lại": BLOCKED B6/J-13. */
class ReadingApprovalController extends BaseController
{
    private ReadingApprovalService $service;
    private BillingPeriodService $periods;

    public function __construct()
    {
        $this->service = new ReadingApprovalService();
        $this->periods = new BillingPeriodService();
    }

    public function index(string $id): void
    {
        $period = $this->periods->find($this->routeId($id)) ?? $this->notFound();
        $zeroOnly = $this->filters(['flag'])['flag'] === 'cl0';

        $this->view('approvals.index', [
            'title' => 'Duyệt chỉ số — kỳ ' . $period['period_code'],
            'period' => $period,
            'zeroOnly' => $zeroOnly,
            'drafts' => $this->service->drafts((int) $period['id'], $zeroOnly),
        ]);
    }

    /** POST reading_ids[] + token_<id> (version token lúc xem) -> duyệt; bản xung đột bị bỏ qua kèm lý do. */
    public function store(string $id): void
    {
        $periodId = $this->routeId($id);
        $items = [];
        foreach (Request::postList('reading_ids') as $readingId) {
            if (ctype_digit($readingId)) {
                $items[(int) $readingId] = Request::postString('token_' . $readingId);
            }
        }
        $this->approveAndReport($periodId, $items);
        $this->redirect(Request::postString('back') === 'reading' && count($items) === 1
            ? '/readings/' . array_key_first($items) : "/periods/{$periodId}/approvals");
    }

    private function approveAndReport(int $periodId, array $items): void
    {
        try {
            $result = $this->service->approve($periodId, $items);
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());

            return;
        }
        if ($result['approved'] !== []) {
            Flash::success('Đã duyệt ' . count($result['approved']) . ' chỉ số (Đã xác nhận).');
        }
        foreach ($result['skipped'] as $readingId => $reason) {
            Flash::error("Chỉ số #{$readingId} không được duyệt: {$reason}");
        }
    }
}
