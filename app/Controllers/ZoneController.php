<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\BuildingService;
use App\Services\ZoneService;

/** FN-02.02 Quản lý khu vực (Building 1 -> N Zone). */
class ZoneController extends BaseController
{
    private ZoneService $service;
    private BuildingService $buildings;

    public function __construct()
    {
        $this->service = new ZoneService();
        $this->buildings = new BuildingService();
    }

    public function index(): void
    {
        $filters = $this->filters(['q', 'building_id', 'zone_type', 'status']);

        $this->view('zones.index', [
            'title' => t('page.zones'),
            'filters' => $filters,
            'pager' => $this->service->list($filters, $this->currentPage()),
            'buildingOptions' => $this->buildings->options(false),
        ]);
    }

    public function create(): void
    {
        $this->view('zones.form', [
            'title' => 'Thêm khu vực',
            'zone' => null,
            'presetBuildingId' => $this->filters(['building_id'])['building_id'],
            'buildingOptions' => $this->buildings->options(true),
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(): void
    {
        $input = $this->only(['building_id', 'code', 'name', 'zone_type']);

        try {
            $id = $this->service->create($input);
        } catch (ValidationException $e) {
            $this->backWithErrors('/zones/create', $e, $input);
        }

        Flash::success('Đã thêm khu vực.');
        $this->redirect('/zones/' . $id);
    }

    public function show(string $id): void
    {
        $zone = $this->service->detail($this->routeId($id)) ?? $this->notFound();

        $this->view('zones.show', ['title' => 'Khu vực ' . $zone['code'], 'zone' => $zone]);
    }

    public function edit(string $id): void
    {
        $zone = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('zones.form', [
            'title' => 'Sửa khu vực ' . $zone['code'],
            'zone' => $zone,
            'presetBuildingId' => '',
            'buildingOptions' => $this->withCurrent($this->buildings->options(true), (int) $zone['building_id']),
            'form' => Flash::pullFormState(),
        ]);
    }

    public function update(string $id): void
    {
        $zoneId = $this->routeId($id);
        $input = $this->only(['building_id', 'code', 'name', 'zone_type', 'updated_at']);

        try {
            $changed = $this->service->update($zoneId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/zones/{$zoneId}/edit", $e, $input);
        }

        $changed ? Flash::success('Đã cập nhật khu vực.') : Flash::info('Không có thay đổi nào.');
        $this->redirect('/zones/' . $zoneId);
    }

    public function deactivate(string $id): void
    {
        $zoneId = $this->routeId($id);

        try {
            $this->service->deactivate($zoneId);
            Flash::success('Đã ngừng khu vực.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/zones/' . $zoneId);
    }

    /** Khi sửa: vẫn hiển thị tòa hiện tại trong dropdown dù tòa đó đã ngừng. */
    private function withCurrent(array $activeOptions, int $currentId): array
    {
        foreach ($activeOptions as $option) {
            if ((int) $option['id'] === $currentId) {
                return $activeOptions;
            }
        }
        $current = $this->buildings->find($currentId);

        return $current !== null ? array_merge([$current], $activeOptions) : $activeOptions;
    }
}
