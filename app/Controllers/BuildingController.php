<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\BuildingService;

/** FN-02.01 Quản lý tòa nhà. RBAC theo route (routes/web.php), nghiệp vụ ở BuildingService. */
class BuildingController extends BaseController
{
    private BuildingService $service;

    public function __construct()
    {
        $this->service = new BuildingService();
    }

    public function index(): void
    {
        $filters = $this->filters(['q', 'status']);

        $this->view('buildings.index', [
            'title' => t('page.buildings'),
            'filters' => $filters,
            'pager' => $this->service->list($filters, $this->currentPage()),
        ]);
    }

    public function create(): void
    {
        $this->view('buildings.form', [
            'title' => 'Thêm tòa nhà',
            'building' => null,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(): void
    {
        $input = $this->only(['code', 'name']);

        try {
            $id = $this->service->create($input);
        } catch (ValidationException $e) {
            $this->backWithErrors('/buildings/create', $e, $input);
        }

        Flash::success('Đã thêm tòa nhà.');
        $this->redirect('/buildings/' . $id);
    }

    public function show(string $id): void
    {
        $building = $this->service->detail($this->routeId($id)) ?? $this->notFound();

        $this->view('buildings.show', ['title' => 'Tòa nhà ' . $building['code'], 'building' => $building]);
    }

    public function edit(string $id): void
    {
        $building = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('buildings.form', [
            'title' => 'Sửa tòa nhà ' . $building['code'],
            'building' => $building,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function update(string $id): void
    {
        $buildingId = $this->routeId($id);
        $input = $this->only(['code', 'name', 'updated_at']);

        try {
            $changed = $this->service->update($buildingId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/buildings/{$buildingId}/edit", $e, $input);
        }

        $changed ? Flash::success('Đã cập nhật tòa nhà.') : Flash::info('Không có thay đổi nào.');
        $this->redirect('/buildings/' . $buildingId);
    }

    public function deactivate(string $id): void
    {
        $buildingId = $this->routeId($id);

        try {
            $this->service->deactivate($buildingId);
            Flash::success('Đã ngừng tòa nhà.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/buildings/' . $buildingId);
    }
}
