<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/**
 * FN-02.06 - bảng parking_vehicles: số xe theo loại của căn hộ
 * (UNIQUE apartment_id + vehicle_type). Chỉ lưu dữ liệu đầu vào cho FN-05.03,
 * không chứa đơn giá/phí.
 */
class ParkingVehicleRepository extends BaseRepository
{
    protected string $table = 'parking_vehicles';

    /** @return array<string, array> vehicle_type => dòng */
    public function mapByApartment(int $apartmentId, bool $forUpdate = false): array
    {
        $rows = $this->fetchAllQuery(
            'SELECT * FROM parking_vehicles WHERE apartment_id = :id' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $apartmentId]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['vehicle_type']] = $row;
        }

        return $map;
    }

    public function setQuantity(int $apartmentId, string $vehicleType, int $quantity, ?int $existingId): void
    {
        if ($existingId !== null) {
            $this->updateById($existingId, ['quantity' => $quantity]);

            return;
        }

        $this->insertRow(['apartment_id' => $apartmentId, 'vehicle_type' => $vehicleType, 'quantity' => $quantity]);
    }
}
