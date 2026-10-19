<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\BillingPeriodService;
use App\Services\ConsumptionTargetService;

/** FN-06.03 Thiết lập mục tiêu (BQL) / FN-06.04 Theo dõi mức đạt mục tiêu (BQL, KT, KTo xem). */
class ConsumptionTargetController extends BaseController
{
    private ConsumptionTargetService $service;

    public function __construct()
    {
        $this->service = new ConsumptionTargetService();
    }

    public function index(): void
    {
        $filters = $this->filters(['period_id', 'utility_type', 'scope_type']);

        $this->view('targets.index', [
            'title' => t('page.targets'),
            'filters' => $filters,
            'periods' => (new BillingPeriodService())->options(),
            'rows' => $this->service->list($filters),
        ]);
    }

    public function tracking(): void
    {
        $filters = $this->filters(['period_id', 'utility_type', 'scope_type']);
        $periods = (new BillingPeriodService())->options();
        if ($filters['period_id'] === '' && $periods !== []) {
            $filters['period_id'] = (string) $periods[0]['id'];
        }

        $this->view('targets.tracking', [
            'title' => t('page.target_tracking'),
            'filters' => $filters,
            'periods' => $periods,
            'result' => $this->service->tracking($filters),
        ]);
    }

    public function create(): void
    {
        $this->view('targets.form', [
            'title' => 'Đặt mục tiêu tiêu thụ',
            't' => null,
            'form' => Flash::pullFormState(),
            'scopes' => $this->service->scopeOptions(),
            'periods' => (new BillingPeriodService())->options(),
        ]);
    }

    public function store(): void
    {
        [$type, $scopeId] = array_pad(explode(':', Request::postString('scope'), 2), 2, '');
        $input = [
            'scope_type' => $type,
            'scope_id' => $scopeId,
            'utility_type' => Request::postString('utility_type'),
            'billing_period_id' => Request::postString('billing_period_id'),
            'target_value' => Request::postString('target_value'),
        ];
        try {
            $this->service->create($input);
            Flash::success('Đã lưu mục tiêu.');
            $this->redirect('/targets?period_id=' . (int) $input['billing_period_id']);
        } catch (ValidationException $e) {
            $this->backWithErrors('/targets/create', $e, $input + ['scope' => Request::postString('scope')]);
        }
    }

    public function edit(string $id): void
    {
        $target = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('targets.form', ['title' => 'Sửa mục tiêu #' . $target['id'], 't' => $target, 'form' => Flash::pullFormState(), 'scopes' => [], 'periods' => []]);
    }

    public function update(string $id): void
    {
        $targetId = $this->routeId($id);
        $input = ['target_value' => Request::postString('target_value'), 'version_token' => Request::postString('version_token')];
        try {
            $this->service->update($targetId, $input);
            Flash::success('Đã cập nhật mục tiêu.');
            $target = $this->service->find($targetId);
            $this->redirect('/targets?period_id=' . (int) $target['billing_period_id']);
        } catch (ValidationException $e) {
            $this->backWithErrors("/targets/{$targetId}/edit", $e, $input);
        }
    }
}
