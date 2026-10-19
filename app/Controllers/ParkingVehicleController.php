<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\ApartmentService;
use App\Services\ParkingVehicleService;

/** FN-02.06 Phương tiện gửi xe của căn hộ (chỉ số lượng theo loại, không có phí). */
class ParkingVehicleController extends BaseController
{
    private ParkingVehicleService $service;
    private ApartmentService $apartments;

    public function __construct()
    {
        $this->service = new ParkingVehicleService();
        $this->apartments = new ApartmentService();
    }

    public function edit(string $id): void
    {
        $apartment = $this->apartments->find($this->routeId($id)) ?? $this->notFound();

        $this->view('vehicles.form', [
            'title' => 'Phương tiện gửi xe - ' . $apartment['code'],
            'apartment' => $apartment,
            'quantities' => $this->service->quantities((int) $apartment['id']),
            'form' => Flash::pullFormState(),
        ]);
    }

    public function update(string $id): void
    {
        $apartmentId = $this->routeId($id);
        $keys = array_map(static fn (string $type): string => 'quantity_' . $type, array_keys(config('master_data.vehicle_type')));
        $input = $this->only($keys);

        try {
            $changed = $this->service->update($apartmentId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/apartments/{$apartmentId}/vehicles/edit", $e, $input);
        }

        $changed ? Flash::success('Đã cập nhật phương tiện gửi xe.') : Flash::info('Không có thay đổi nào.');
        $this->redirect("/apartments/{$apartmentId}#vehicles");
    }
}
