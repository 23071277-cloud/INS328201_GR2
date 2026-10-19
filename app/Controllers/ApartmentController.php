<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\ApartmentService;
use App\Services\BuildingService;
use App\Services\ZoneService;

/** FN-02.03 Quản lý căn hộ; trang chi tiết gồm công tơ (02.05), chủ hộ (02.04), xe (02.06). */
class ApartmentController extends BaseController
{
    private ApartmentService $service;
    private BuildingService $buildings;
    private ZoneService $zones;

    public function __construct()
    {
        $this->service = new ApartmentService();
        $this->buildings = new BuildingService();
        $this->zones = new ZoneService();
    }

    public function index(): void
    {
        $filters = $this->filters(['q', 'building_id', 'zone_id', 'occupancy_status', 'status']);

        $this->view('apartments.index', [
            'title' => t('page.apartments'),
            'filters' => $filters,
            'pager' => $this->service->list($filters, $this->currentPage()),
            'buildingOptions' => $this->buildings->options(false),
            'zoneOptions' => $this->zones->options(false),
        ]);
    }

    public function create(): void
    {
        $this->view('apartments.form', [
            'title' => 'Thêm căn hộ',
            'apartment' => null,
            'buildingLocked' => false,
            'presetBuildingId' => $this->filters(['building_id'])['building_id'],
            'buildingOptions' => $this->buildings->options(true),
            'zoneOptions' => $this->zones->options(true),
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(): void
    {
        $input = $this->only(['building_id', 'zone_id', 'code', 'area', 'occupancy_status']);

        try {
            $id = $this->service->create($input);
        } catch (ValidationException $e) {
            $this->backWithErrors('/apartments/create', $e, $input);
        }

        Flash::success('Đã thêm căn hộ.');
        $this->redirect('/apartments/' . $id);
    }

    public function show(string $id): void
    {
        $apartment = $this->service->detail($this->routeId($id)) ?? $this->notFound();

        $this->view('apartments.show', ['title' => 'Căn hộ ' . $apartment['code'], 'apartment' => $apartment]);
    }

    public function edit(string $id): void
    {
        $apartment = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $buildingOptions = $this->buildings->options(true);
        if (!in_array((int) $apartment['building_id'], array_map('intval', array_column($buildingOptions, 'id')), true)) {
            array_unshift($buildingOptions, $this->buildings->find((int) $apartment['building_id']));
        }
        $zoneOptions = $this->zones->options(true);
        if ($apartment['zone_id'] !== null
            && !in_array((int) $apartment['zone_id'], array_map('intval', array_column($zoneOptions, 'id')), true)) {
            array_unshift($zoneOptions, $this->zones->find((int) $apartment['zone_id']));
        }

        $this->view('apartments.form', [
            'title' => 'Sửa căn hộ ' . $apartment['code'],
            'apartment' => $apartment,
            'buildingLocked' => $this->service->hasMeters((int) $apartment['id']), // D7 - chỉ để hiển thị, Service vẫn kiểm
            'presetBuildingId' => '',
            'buildingOptions' => $buildingOptions,
            'zoneOptions' => $zoneOptions,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function update(string $id): void
    {
        $apartmentId = $this->routeId($id);
        $input = $this->only(['building_id', 'zone_id', 'code', 'area', 'occupancy_status', 'updated_at']);

        try {
            $changed = $this->service->update($apartmentId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/apartments/{$apartmentId}/edit", $e, $input);
        }

        $changed ? Flash::success('Đã cập nhật căn hộ.') : Flash::info('Không có thay đổi nào.');
        $this->redirect('/apartments/' . $apartmentId);
    }

    public function deactivate(string $id): void
    {
        $apartmentId = $this->routeId($id);

        try {
            $this->service->deactivate($apartmentId);
            Flash::success('Đã ngừng căn hộ.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/apartments/' . $apartmentId);
    }
}
