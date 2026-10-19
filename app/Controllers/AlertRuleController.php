<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\AlertEvaluationService;
use App\Services\AlertRuleService;
use App\Services\BillingPeriodService;

/** FN-07.01 Cấu hình quy tắc ngưỡng (BQL; Kỹ thuật, Kế toán xem). */
class AlertRuleController extends BaseController
{
    private AlertRuleService $service;

    public function __construct()
    {
        $this->service = new AlertRuleService();
    }

    public function index(): void
    {
        $filters = $this->filters(['status', 'utility_type', 'measure_type']);

        $this->view('alert_rules.index', ['title' => t('page.alert_rules'), 'filters' => $filters, 'rows' => $this->service->list($filters)]);
    }

    /** Chi tiết + chạy thử trên 1 kỳ ([ĐX] FN-07.01 mục 6) - GET, không ghi dữ liệu. */
    public function show(string $id): void
    {
        $rule = $this->service->find($this->routeId($id)) ?? $this->notFound();
        $periods = (new BillingPeriodService())->options();
        $periodId = $this->filters(['period_id'])['period_id'];
        $dry = null;
        if (ctype_digit($periodId)) {
            try {
                $dry = (new AlertEvaluationService())->evaluatePeriod((int) $periodId, (int) $rule['id'], true);
            } catch (ValidationException $e) {
                Flash::error($e->getMessage());
            }
        }

        $this->view('alert_rules.show', ['title' => 'Quy tắc ngưỡng #' . $rule['id'], 'r' => $rule, 'periods' => $periods, 'periodId' => $periodId, 'dry' => $dry]);
    }

    public function create(): void
    {
        $this->view('alert_rules.form', ['title' => 'Thêm quy tắc ngưỡng', 'r' => null, 'form' => Flash::pullFormState(), 'zones' => $this->service->zones()]);
    }

    public function store(): void
    {
        $input = $this->ruleInput();
        try {
            $id = $this->service->create($input);
            Flash::success('Đã lưu quy tắc ngưỡng (Bật). Áp dụng từ lần đánh giá tiếp theo.');
            $this->redirect('/alert-rules/' . $id);
        } catch (ValidationException $e) {
            $this->backWithErrors('/alert-rules/create', $e, $input);
        }
    }

    public function edit(string $id): void
    {
        $rule = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('alert_rules.form', ['title' => 'Sửa quy tắc #' . $rule['id'], 'r' => $rule, 'form' => Flash::pullFormState(), 'zones' => $this->service->zones()]);
    }

    public function update(string $id): void
    {
        $ruleId = $this->routeId($id);
        $input = $this->ruleInput() + ['version_token' => Request::postString('version_token')];
        try {
            $this->service->update($ruleId, $input);
            Flash::success('Đã cập nhật quy tắc. Cảnh báo đã sinh giữ nguyên; quy tắc mới áp từ lần đánh giá sau.');
            $this->redirect('/alert-rules/' . $ruleId);
        } catch (ValidationException $e) {
            $this->backWithErrors("/alert-rules/{$ruleId}/edit", $e, $input);
        }
    }

    public function status(string $id): void
    {
        $ruleId = $this->routeId($id);
        try {
            $this->service->setStatus($ruleId, Request::postString('status'), Request::postString('version_token'));
            Flash::success('Đã đổi trạng thái quy tắc.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/alert-rules/' . $ruleId);
    }

    private function ruleInput(): array
    {
        $out = [];
        foreach (['name', 'measure_type', 'utility_type', 'compare_base', 'operator', 'threshold_value', 'scope_type', 'priority_level'] as $k) {
            $out[$k] = Request::postString($k);
        }
        $out['scope_value'] = $out['scope_type'] === 'zone' ? Request::postString('scope_zone') : Request::postString('scope_usage');

        return $out;
    }
}
