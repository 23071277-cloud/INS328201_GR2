<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\ApartmentService;
use App\Services\ResidentService;

/** FN-02.04 Quản lý chủ hộ/khách thuê - danh sách theo căn hộ nằm ở trang chi tiết căn hộ. */
class ResidentController extends BaseController
{
    private const FIELDS = ['full_name', 'resident_role', 'phone', 'email', 'effective_from', 'effective_to'];

    private ResidentService $service;
    private ApartmentService $apartments;

    public function __construct()
    {
        $this->service = new ResidentService();
        $this->apartments = new ApartmentService();
    }

    public function create(string $id): void
    {
        $apartment = $this->apartments->find($this->routeId($id)) ?? $this->notFound();

        $this->view('residents.form', [
            'title' => 'Thêm chủ hộ/khách thuê - ' . $apartment['code'],
            'apartment' => $apartment,
            'resident' => null,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(string $id): void
    {
        $apartmentId = $this->routeId($id);
        $input = $this->only(self::FIELDS);

        try {
            $this->service->create($apartmentId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/apartments/{$apartmentId}/residents/create", $e, $input);
        }

        Flash::success('Đã thêm chủ hộ/khách thuê.');
        $this->redirect("/apartments/{$apartmentId}#residents");
    }

    public function edit(string $id): void
    {
        $resident = $this->service->find($this->routeId($id)) ?? $this->notFound();
        $apartment = $this->apartments->find((int) $resident['apartment_id']) ?? $this->notFound();

        $this->view('residents.form', [
            'title' => 'Sửa chủ hộ/khách thuê - ' . $apartment['code'],
            'apartment' => $apartment,
            'resident' => $resident,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function update(string $id): void
    {
        $residentId = $this->routeId($id);
        $resident = $this->service->find($residentId) ?? $this->notFound();
        $input = $this->only(array_merge(self::FIELDS, ['updated_at']));

        try {
            $changed = $this->service->update($residentId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/residents/{$residentId}/edit", $e, $input);
        }

        $changed ? Flash::success('Đã cập nhật chủ hộ/khách thuê.') : Flash::info('Không có thay đổi nào.');
        $this->redirect('/apartments/' . $resident['apartment_id'] . '#residents');
    }

    public function deactivate(string $id): void
    {
        $residentId = $this->routeId($id);
        $resident = $this->service->find($residentId) ?? $this->notFound();

        try {
            $this->service->deactivate($residentId);
            Flash::success('Đã kết thúc hiệu lực chủ hộ/khách thuê.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/apartments/' . $resident['apartment_id'] . '#residents');
    }
}
