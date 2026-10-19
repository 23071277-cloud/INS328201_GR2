<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\TariffService;

/** FN-03.01 Quản lý biểu giá điện/nước (phiên bản + bộ sửa bậc). */
class TariffController extends BaseController
{
    private const FIELDS = ['utility_type', 'usage_type', 'vat_percent', 'business_unit_price', 'effective_date'];

    private TariffService $service;

    public function __construct()
    {
        $this->service = new TariffService();
    }

    public function index(): void
    {
        $filters = $this->filters(['utility_type', 'usage_type', 'lifecycle', 'effective_from', 'effective_to']);

        $this->view('tariffs.index', [
            'title' => t('page.tariffs'),
            'filters' => $filters,
            'pager' => $this->service->list($filters, $this->currentPage()),
        ]);
    }

    /** ?from={id}: tạo phiên bản mới, điền sẵn giá/bậc của phiên bản đó (không sửa phiên bản cũ). */
    public function create(): void
    {
        $fromId = $this->filters(['from'])['from'];
        $source = ctype_digit($fromId) ? $this->service->find((int) $fromId) : null;

        $this->view('tariffs.form', [
            'title' => 'Thêm phiên bản biểu giá',
            'tariff' => null,
            'source' => $source,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(): void
    {
        $input = $this->only(self::FIELDS) + ['tiers' => $this->tiersInput()];
        $back = '/tariffs/create' . (ctype_digit($this->filters(['from'])['from']) ? '?from=' . $this->filters(['from'])['from'] : '');

        try {
            $id = $this->service->create($input);
        } catch (ValidationException $e) {
            $this->backWithErrors($back, $e, $input);
        }

        Flash::success('Đã tạo phiên bản biểu giá mới. Phiên bản cũ được giữ nguyên trong lịch sử.');
        $this->redirect('/tariffs/' . $id);
    }

    public function show(string $id): void
    {
        $tariff = $this->service->detail($this->routeId($id)) ?? $this->notFound();

        $this->view('tariffs.show', ['title' => 'Biểu giá #' . $tariff['id'], 'tariff' => $tariff]);
    }

    public function edit(string $id): void
    {
        $tariff = $this->service->find($this->routeId($id)) ?? $this->notFound();
        $reason = $this->service->editBlockReason($tariff);
        if ($reason !== null) {
            Flash::error('Không thể sửa phiên bản này: ' . $reason);
            $this->redirect('/tariffs/' . $tariff['id']);
        }

        $this->view('tariffs.form', [
            'title' => 'Sửa biểu giá #' . $tariff['id'],
            'tariff' => $tariff,
            'source' => null,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function update(string $id): void
    {
        $tariffId = $this->routeId($id);
        $input = $this->only(['vat_percent', 'business_unit_price', 'effective_date', 'version_token']) + ['tiers' => $this->tiersInput()];

        try {
            $changed = $this->service->update($tariffId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/tariffs/{$tariffId}/edit", $e, $input);
        }

        $changed ? Flash::success('Đã cập nhật phiên bản biểu giá.') : Flash::info('Không có thay đổi nào.');
        $this->redirect('/tariffs/' . $tariffId);
    }

    /** Bậc từ form: tiers[i][tier_from|tier_to|unit_price]. */
    private function tiersInput(): array
    {
        return $this->postRows('tiers', ['tier_from', 'tier_to', 'unit_price']);
    }
}
