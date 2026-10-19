<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\BaselineService;

/** FN-06.01 Cấu hình baseline (BQL): danh sách, tính thử, lưu phiên bản, sửa/ngừng phiên bản chưa dùng cho kỳ khóa. */
class BaselineController extends BaseController
{
    private BaselineService $service;

    public function __construct()
    {
        $this->service = new BaselineService();
    }

    public function index(): void
    {
        $filters = $this->filters(['scope_type', 'utility_type', 'method']);

        $this->view('baselines.index', ['title' => t('page.baselines'), 'filters' => $filters, 'rows' => $this->service->list($filters)]);
    }

    public function create(): void
    {
        $this->renderForm(null, Flash::pullFormState(), null);
    }

    /** intent=preview: tính thử, không lưu; intent=save: lưu phiên bản. */
    public function store(): void
    {
        $input = $this->formInput();
        if (Request::postString('intent') === 'preview') {
            $this->preview(null, $input);

            return;
        }
        try {
            $id = $this->service->create($input);
            Flash::success('Đã lưu baseline (phiên bản mới).');
            $this->redirect('/baselines/' . $id);
        } catch (ValidationException $e) {
            $this->backWithErrors('/baselines/create', $e, $input + ['scope' => Request::postString('scope')]);
        }
    }

    public function show(string $id): void
    {
        $row = $this->service->detail($this->routeId($id)) ?? $this->notFound();

        $this->view('baselines.show', ['title' => 'Baseline #' . $row['id'], 'b' => $row, 'blockReason' => $this->service->editBlockReason($row)]);
    }

    public function edit(string $id): void
    {
        $row = $this->service->find($this->routeId($id)) ?? $this->notFound();
        $reason = $this->service->editBlockReason($row);
        if ($reason !== null) {
            Flash::error($reason);
            $this->redirect('/baselines/' . $row['id']);
        }
        $this->renderForm($row, Flash::pullFormState(), null);
    }

    public function update(string $id): void
    {
        $baselineId = $this->routeId($id);
        $input = $this->formInput() + ['version_token' => Request::postString('version_token')];
        if (Request::postString('intent') === 'preview') {
            $row = $this->service->find($baselineId) ?? $this->notFound();
            $this->preview($row, $input + ['scope_type' => $row['scope_type'], 'scope_id' => (string) $row['scope_id'], 'utility_type' => $row['utility_type']]);

            return;
        }
        try {
            $this->service->update($baselineId, $input);
            Flash::success('Đã cập nhật baseline.');
            $this->redirect('/baselines/' . $baselineId);
        } catch (ValidationException $e) {
            $this->backWithErrors("/baselines/{$baselineId}/edit", $e, $input);
        }
    }

    public function deactivate(string $id): void
    {
        $baselineId = $this->routeId($id);
        try {
            $this->service->deactivate($baselineId, Request::postString('version_token'));
            Flash::success('Baseline chuyển sang Hết hiệu lực.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/baselines/' . $baselineId);
    }

    // ------------------------------------------------------------------

    private function preview(?array $row, array $input): void
    {
        try {
            $result = $this->service->preview($input);
            $form = ['errors' => [], 'old' => $input + ['scope' => Request::postString('scope')]];
        } catch (ValidationException $e) {
            $result = null;
            $form = ['errors' => $e->errors(), 'old' => $input + ['scope' => Request::postString('scope')]];
        }
        $this->renderForm($row, $form, $result);
    }

    private function renderForm(?array $row, array $form, ?array $preview): void
    {
        $this->view('baselines.form', [
            'title' => $row === null ? 'Lập baseline' : 'Sửa baseline #' . $row['id'],
            'b' => $row,
            'form' => $form,
            'preview' => $preview,
            'scopes' => $row === null ? $this->service->scopeOptions() : [],
        ]);
    }

    /** "scope" = "loại:id" từ ô chọn; mọi giá trị là chuỗi vô hướng (Request). */
    private function formInput(): array
    {
        [$type, $scopeId] = array_pad(explode(':', Request::postString('scope'), 2), 2, '');

        return [
            'scope_type' => $type,
            'scope_id' => $scopeId,
            'utility_type' => Request::postString('utility_type'),
            'method' => Request::postString('method'),
            'n_periods' => Request::postString('n_periods'),
            'manual_value' => Request::postString('manual_value'),
            'effective_date' => Request::postString('effective_date'),
        ];
    }
}
