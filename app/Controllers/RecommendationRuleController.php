<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\RecommendationRuleService;

/** FN-08.01 Quản lý quy tắc khuyến nghị (BQL - [ĐX], J-02). */
class RecommendationRuleController extends BaseController
{
    private RecommendationRuleService $service;

    public function __construct()
    {
        $this->service = new RecommendationRuleService();
    }

    public function index(): void
    {
        $filters = $this->filters(['status', 'condition_type']);

        $this->view('recommendation_rules.index', ['title' => t('page.recommendation_rules'), 'filters' => $filters, 'rows' => $this->service->list($filters)]);
    }

    public function show(string $id): void
    {
        $rule = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('recommendation_rules.show', ['title' => 'Quy tắc khuyến nghị #' . $rule['id'], 'r' => $rule]);
    }

    public function create(): void
    {
        $this->view('recommendation_rules.form', ['title' => 'Thêm quy tắc khuyến nghị', 'r' => null, 'form' => Flash::pullFormState()]);
    }

    public function store(): void
    {
        $input = $this->ruleInput();
        try {
            $id = $this->service->create($input);
            Flash::success('Đã lưu quy tắc khuyến nghị (Bật).');
            $this->redirect('/recommendation-rules/' . $id);
        } catch (ValidationException $e) {
            $this->backWithErrors('/recommendation-rules/create', $e, $input);
        }
    }

    public function edit(string $id): void
    {
        $rule = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('recommendation_rules.form', ['title' => 'Sửa quy tắc khuyến nghị #' . $rule['id'], 'r' => $rule, 'form' => Flash::pullFormState()]);
    }

    public function update(string $id): void
    {
        $ruleId = $this->routeId($id);
        $input = $this->ruleInput() + ['version_token' => Request::postString('version_token')];
        try {
            $this->service->update($ruleId, $input);
            Flash::success('Đã cập nhật quy tắc. Khuyến nghị đã sinh giữ nguyên nội dung lúc sinh.');
            $this->redirect('/recommendation-rules/' . $ruleId);
        } catch (ValidationException $e) {
            $this->backWithErrors("/recommendation-rules/{$ruleId}/edit", $e, $input);
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
        $this->redirect('/recommendation-rules/' . $ruleId);
    }

    private function ruleInput(): array
    {
        $out = [];
        foreach (['name', 'condition_type', 'n_periods', 'utility_type', 'recommendation_text'] as $k) {
            $out[$k] = Request::postString($k);
        }

        return $out;
    }
}
