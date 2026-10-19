<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\ApartmentService;
use App\Services\BuildingService;
use App\Services\MeterService;

/** FN-02.05 Quản lý đồng hồ đo (công tơ điện/nước). */
class MeterController extends BaseController
{
    private const FIELDS = ['apartment_id', 'meter_code', 'utility_type', 'usage_type', 'display_label', 'initial_reading'];

    private MeterService $service;
    private ApartmentService $apartments;
    private BuildingService $buildings;

    public function __construct()
    {
        $this->service = new MeterService();
        $this->apartments = new ApartmentService();
        $this->buildings = new BuildingService();
    }

    public function index(): void
    {
        $filters = $this->filters(['q', 'building_id', 'apartment_id', 'utility_type', 'usage_type', 'status']);

        $this->view('meters.index', [
            'title' => t('page.meters'),
            'filters' => $filters,
            'pager' => $this->service->list($filters, $this->currentPage()),
            'buildingOptions' => $this->buildings->options(false),
        ]);
    }

    public function create(): void
    {
        $this->view('meters.form', [
            'title' => 'Thêm công tơ',
            'meter' => null,
            'presetApartmentId' => $this->filters(['apartment_id'])['apartment_id'],
            'apartmentOptions' => $this->apartments->options(true),
            'existingCodes' => $this->service->existingCodes(),
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(): void
    {
        $input = $this->only(self::FIELDS);

        try {
            $id = $this->service->create($input);
        } catch (ValidationException $e) {
            $query = $input['apartment_id'] !== '' && ctype_digit((string) $input['apartment_id']) ? '?apartment_id=' . $input['apartment_id'] : '';
            $this->backWithErrors('/meters/create' . $query, $e, $input);
        }

        Flash::success('Đã thêm công tơ.');
        $this->redirect('/meters/' . $id);
    }

    public function show(string $id): void
    {
        $meter = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('meters.show', ['title' => 'Công tơ ' . $meter['meter_code'], 'meter' => $meter]);
    }

    public function edit(string $id): void
    {
        $meter = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $apartmentOptions = $this->apartments->options(true);
        if (!in_array((int) $meter['apartment_id'], array_map('intval', array_column($apartmentOptions, 'id')), true)) {
            array_unshift($apartmentOptions, [
                'id' => $meter['apartment_id'],
                'code' => $meter['apartment_code'],
                'status' => $meter['apartment_status'],
                'building_code' => $meter['building_code'],
            ]);
        }

        $this->view('meters.form', [
            'title' => 'Sửa công tơ ' . $meter['meter_code'],
            'meter' => $meter,
            'presetApartmentId' => '',
            'apartmentOptions' => $apartmentOptions,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function update(string $id): void
    {
        $meterId = $this->routeId($id);
        $input = $this->only(array_merge(self::FIELDS, ['updated_at']));

        try {
            $changed = $this->service->update($meterId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/meters/{$meterId}/edit", $e, $input);
        }

        $changed ? Flash::success('Đã cập nhật công tơ.') : Flash::info('Không có thay đổi nào.');
        $this->redirect('/meters/' . $meterId);
    }

    public function deactivate(string $id): void
    {
        $meterId = $this->routeId($id);

        try {
            $kept = $this->service->deactivate($meterId);
            Flash::success($kept > 0
                ? "Đã ngừng công tơ. {$kept} bản ghi chỉ số của công tơ được giữ nguyên."
                : 'Đã ngừng công tơ.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/meters/' . $meterId);
    }
}
