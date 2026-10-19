<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\PaymentReconciliationService;
use App\Services\PaymentStatusService;

/** Payment module: dashboard thanh toán theo kỳ + hàng chờ giao dịch cần kiểm tra (khớp thủ công / bỏ qua). */
class PaymentController extends BaseController
{
    public function index(): void
    {
        $status = new PaymentStatusService();
        if (!$status->available()) {
            $this->view('payments.index', ['title' => t('page.payments'), 'available' => false]);

            return;
        }
        $periods = $status->periods();
        $periodId = (int) Request::queryString('period_id', (string) ($periods[0]['id'] ?? 0));
        if (!in_array($periodId, array_map('intval', array_column($periods, 'id')), true)) {
            $periodId = (int) ($periods[0]['id'] ?? 0);
        }
        $this->view('payments.index', [
            'title' => t('page.payments'),
            'available' => true,
            'periods' => $periods,
            'summary' => $periodId > 0 ? $status->periodSummary($periodId) : null,
            'recent' => $status->recent(20),
        ]);
    }

    public function review(): void
    {
        $status = new PaymentStatusService();
        $this->view('payments.review', [
            'title' => t('page.payment_review'),
            'available' => $status->available(),
            'queue' => $status->available() ? $status->reviewQueue() : [],
            'form' => Flash::pullFormState(),
        ]);
    }

    public function match(string $id): void
    {
        $txnId = $this->routeId($id);
        try {
            $r = (new PaymentReconciliationService())->matchManually($txnId, Request::postString('ref'), Request::postString('note'));
            Flash::success('Đã khớp giao dịch ' . $r['transaction_id'] . ' vào ' . PaymentStatusService::ref((int) $r['charge_id']) . ' — bản tính Đã thanh toán.'
                . (count($r['emails']) > 0 ? ' Đã gửi email xác nhận (' . count($r['emails']) . ').' : ''));
        } catch (ValidationException $e) {
            foreach (array_merge(...array_values($e->errors())) as $m) {
                Flash::error($m);
            }
        }
        $this->redirect('/payments/review');
    }

    public function ignore(string $id): void
    {
        $txnId = $this->routeId($id);
        try {
            (new PaymentReconciliationService())->ignore($txnId, Request::postString('note'));
            Flash::success('Đã bỏ qua giao dịch (đã xử lý ngoài hệ thống).');
        } catch (ValidationException $e) {
            foreach (array_merge(...array_values($e->errors())) as $m) {
                Flash::error($m);
            }
        }
        $this->redirect('/payments/review');
    }
}
