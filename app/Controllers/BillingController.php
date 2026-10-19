<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\BillingCalculationService;
use App\Services\BillingConfirmationService;
use App\Services\BillingPeriodService;
use App\Services\PeriodClosingService;

/**
 * FN-05.02 - 05.06: danh sách tính phí kỳ, tính phí, chi tiết bản tính, Kế toán xác nhận / trả lại,
 * nợ cũ, BQL duyệt (tuỳ chọn J-18). Controller chỉ đọc request -> gọi Service -> redirect/render:
 * không nhận số tiền / CL nào từ client (chỉ id, version token, lý do, nợ cũ).
 */
class BillingController extends BaseController
{
    private BillingCalculationService $calculation;
    private BillingConfirmationService $confirmation;
    private BillingPeriodService $periods;

    public function __construct()
    {
        $this->calculation = new BillingCalculationService();
        $this->confirmation = new BillingConfirmationService();
        $this->periods = new BillingPeriodService();
    }

    /** Billing Calculation List. */
    public function index(string $id): void
    {
        $period = $this->periods->find($this->routeId($id)) ?? $this->notFound();

        $this->view('billing.index', [
            'title' => 'Tính phí — kỳ ' . $period['period_code'],
            'period' => $period,
            'rows' => $this->calculation->overview((int) $period['id']),
            'blockers' => can('billing_periods.lock') && $period['status'] !== 'da_khoa'
                ? (new PeriodClosingService())->lockBlockers((int) $period['id']) : null,
        ]);
    }

    /** POST [apartment_id] -> tính phí cả kỳ hoặc 1 căn (J-14: theo lệnh của Kế toán). */
    public function calculate(string $id): void
    {
        $periodId = $this->routeId($id);
        $apartment = Request::postString('apartment_id');
        $back = Request::postString('back');
        try {
            $r = $this->calculation->calculatePeriod($periodId, ctype_digit($apartment) ? (int) $apartment : null);
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
            $this->redirect("/periods/{$periodId}/billing");
        }

        $parts = [];
        foreach (['created' => 'tính mới', 'recalculated' => 'tính lại (về Đã tính)', 'unchanged' => 'không đổi'] as $key => $label) {
            if ($r[$key] !== []) {
                $parts[] = count($r[$key]) . ' căn ' . $label;
            }
        }
        if ($parts !== []) {
            Flash::success('Tính phí: ' . implode(', ', $parts) . '.');
        }
        foreach ($r['errors'] as $messages) {
            foreach ($messages as $message) {
                Flash::error($message);
            }
        }
        foreach (array_unique($r['warnings']) as $warning) {
            Flash::info($warning);
        }
        $chargeId = $r['created'][0] ?? $r['recalculated'][0] ?? $r['unchanged'][0] ?? null;
        $this->redirect($back === 'charge' && $chargeId !== null ? '/charges/' . $chargeId : "/periods/{$periodId}/billing");
    }

    /** Accountant Review List (Kế toán: bản Đã tính). */
    public function review(string $id): void
    {
        $this->reviewPage($id, 'confirm');
    }

    /** BQL duyệt (tuỳ chọn, J-18): bản Đã xác nhận. */
    public function approvals(string $id): void
    {
        $this->reviewPage($id, 'approve');
    }

    public function confirm(string $id): void
    {
        $periodId = $this->routeId($id);
        $this->bulkAndReport(fn (array $items): array => $this->confirmation->confirm($periodId, $items), 'Đã xác nhận');
        $this->redirect($this->backPath("/periods/{$periodId}/billing/review"));
    }

    public function approve(string $id): void
    {
        $periodId = $this->routeId($id);
        $this->bulkAndReport(fn (array $items): array => $this->confirmation->approve($periodId, $items), 'Đã duyệt');
        $this->redirect($this->backPath("/periods/{$periodId}/billing/approvals"));
    }

    /** Apartment Charge Detail / Accountant Review Detail. */
    public function show(string $id): void
    {
        $detail = $this->calculation->detail($this->routeId($id)) ?? $this->notFound();

        $this->view('billing.show', [
            'title' => 'Bản tính ' . $detail['charge']['apartment_code'] . ' — kỳ ' . $detail['charge']['period_code'],
            'd' => $detail,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function returnCharge(string $id): void
    {
        $chargeId = $this->routeId($id);
        $reason = Request::postString('rejection_reason');
        try {
            $this->confirmation->returnCharge($chargeId, $reason, Request::postString('version_token'));
            Flash::success('Đã trả lại bản tính kèm lý do (Bị trả lại).');
        } catch (ValidationException $e) {
            $this->backWithErrors('/charges/' . $chargeId, $e, ['rejection_reason' => $reason]);
        }
        $this->redirect('/charges/' . $chargeId);
    }

    public function oldDebt(string $id): void
    {
        $chargeId = $this->routeId($id);
        $amount = Request::postString('old_debt_amount');
        $reason = Request::postString('reason');
        try {
            $this->confirmation->updateOldDebt($chargeId, $amount, $reason, Request::postString('version_token'));
            Flash::success('Đã cập nhật nợ cũ và tổng phải thu.');
        } catch (ValidationException $e) {
            $this->backWithErrors('/charges/' . $chargeId, $e, ['old_debt_amount' => $amount, 'reason' => $reason]);
        }
        $this->redirect('/charges/' . $chargeId);
    }

    // ------------------------------------------------------------------

    private function reviewPage(string $id, string $mode): void
    {
        $period = $this->periods->find($this->routeId($id)) ?? $this->notFound();
        $status = $mode === 'confirm' ? 'da_tinh' : 'da_xac_nhan';
        $rows = array_values(array_filter(
            $this->calculation->overview((int) $period['id']),
            static fn (array $row): bool => $row['charge'] !== null && $row['charge']['status'] === $status
        ));

        $this->view('billing.review', [
            'title' => ($mode === 'confirm' ? 'Kiểm tra & xác nhận tính phí' : 'BQL duyệt tính phí') . ' — kỳ ' . $period['period_code'],
            'period' => $period,
            'rows' => $rows,
            'mode' => $mode,
            'j16Blocking' => (new \App\Services\NoUsageConsumptionService())->blockingOfPeriod((int) $period['id']), // [BA Proposal J-16]
        ]);
    }

    /** Quay lại trang chi tiết khi thao tác từ đó (1 bản tính), ngược lại về danh sách. */
    private function backPath(string $listPath): string
    {
        $ids = Request::postList('charge_ids');

        return Request::postString('back') === 'charge' && count($ids) === 1 && ctype_digit($ids[0]) ? '/charges/' . $ids[0] : $listPath;
    }

    /** POST charge_ids[] + token_<id>. */
    private function bulkAndReport(callable $action, string $doneLabel): void
    {
        $items = [];
        foreach (Request::postList('charge_ids') as $chargeId) {
            if (ctype_digit($chargeId)) {
                $items[(int) $chargeId] = Request::postString('token_' . $chargeId);
            }
        }
        try {
            $result = $action($items);
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());

            return;
        }
        if ($result['done'] !== []) {
            Flash::success("{$doneLabel} " . count($result['done']) . ' bản tính.');
        }
        foreach ($result['skipped'] as $chargeId => $reason) {
            Flash::error("Bản tính #{$chargeId} bị bỏ qua: {$reason}");
        }
    }
}
