<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\FixedFeeService;

/** FN-03.02 Quản lý phí cố định & phí gửi xe. */
class FixedFeeController extends BaseController
{
    private FixedFeeService $service;

    public function __construct()
    {
        $this->service = new FixedFeeService();
    }

    public function index(): void
    {
        $filters = $this->filters(['fee_type']);

        $this->view('fees.index', [
            'title' => t('page.fees'),
            'filters' => $filters,
            'groups' => $this->service->overview($filters['fee_type']),
        ]);
    }

    public function create(): void
    {
        $this->view('fees.form', [
            'title' => 'Thêm đơn giá phí',
            'fee' => null,
            'presetFeeType' => $this->filters(['fee_type'])['fee_type'],
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(): void
    {
        $input = $this->only(['fee_type', 'unit_price', 'effective_date']);

        try {
            $this->service->create($input);
        } catch (ValidationException $e) {
            $query = array_key_exists((string) $input['fee_type'], config('pricing.fee_type')) ? '?fee_type=' . $input['fee_type'] : '';
            $this->backWithErrors('/fees/create' . $query, $e, $input);
        }

        Flash::success('Đã thêm đơn giá mới. Đơn giá cũ được giữ nguyên trong lịch sử.');
        $this->redirect('/fees?fee_type=' . urlencode((string) $input['fee_type']));
    }

    public function edit(string $id): void
    {
        $fee = $this->service->find($this->routeId($id)) ?? $this->notFound();
        if ($fee['edit_block_reason'] !== null) {
            Flash::error('Không thể sửa đơn giá này: ' . $fee['edit_block_reason']);
            $this->redirect('/fees?fee_type=' . urlencode($fee['fee_type']));
        }

        $this->view('fees.form', [
            'title' => 'Sửa đơn giá phí',
            'fee' => $fee,
            'presetFeeType' => '',
            'form' => Flash::pullFormState(),
        ]);
    }

    public function update(string $id): void
    {
        $feeId = $this->routeId($id);
        $fee = $this->service->find($feeId) ?? $this->notFound();
        $input = $this->only(['unit_price', 'effective_date', 'version_token']);

        try {
            $changed = $this->service->update($feeId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/fees/{$feeId}/edit", $e, $input);
        }

        $changed ? Flash::success('Đã cập nhật đơn giá.') : Flash::info('Không có thay đổi nào.');
        $this->redirect('/fees?fee_type=' . urlencode($fee['fee_type']));
    }
}
