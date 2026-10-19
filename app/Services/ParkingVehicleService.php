<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\ApartmentRepository;
use App\Repositories\ParkingVehicleRepository;

/**
 * FN-02.06 Quản lý phương tiện gửi xe | [SL] 2.1 nhóm phí giữ xe; BR-10 (SL >= 0);
 * VAL-02.08 "Số lượng xe phải là số nguyên ≥ 0."
 * Chỉ lưu số lượng xe theo loại - KHÔNG chứa đơn giá/phí (BR-17 tính ở FN-05.03).
 * Phân quyền: [NEED BA CONFIRMATION J-02] (tài liệu: "BQL hoặc Kế toán") - tạm BQL sửa.
 */
class ParkingVehicleService extends BaseService
{
    private ParkingVehicleRepository $vehicles;
    private ApartmentRepository $apartments;

    public function __construct()
    {
        $this->vehicles = new ParkingVehicleRepository();
        $this->apartments = new ApartmentRepository();
    }

    /** @return array<string,int> vehicle_type => số lượng (0 nếu chưa khai báo) */
    public function quantities(int $apartmentId): array
    {
        $map = $this->vehicles->mapByApartment($apartmentId);
        $result = [];
        foreach (array_keys(config('master_data.vehicle_type')) as $type) {
            $result[$type] = (int) ($map[$type]['quantity'] ?? 0);
        }

        return $result;
    }

    /**
     * $input: ['quantity_<vehicle_type>' => '...'] cho mọi loại xe.
     * @return bool false nếu không có thay đổi
     */
    public function update(int $apartmentId, array $input): bool
    {
        return $this->transaction($this->vehicles, function () use ($apartmentId, $input): bool {
            $apartment = $this->apartments->findForUpdate($apartmentId);
            if ($apartment === null) {
                throw ValidationException::withMessage('Căn hộ không tồn tại.');
            }
            if ($apartment['status'] !== 'hoat_dong') {
                // D5 [TECHNICAL ASSUMPTION]
                throw ValidationException::withMessage('Căn hộ đã ngừng hoạt động, không thể cập nhật phương tiện.');
            }

            $types = array_keys(config('master_data.vehicle_type'));
            $data = [];
            foreach ($types as $type) {
                $data['quantity_' . $type] = (string) ($input['quantity_' . $type] ?? '');
            }

            $v = new Validator($data);
            foreach ($types as $type) {
                $v->required('quantity_' . $type, 'Vui lòng nhập số lượng (nhập 0 nếu không có).')
                    ->integer('quantity_' . $type, 0, 65535, 'Số lượng xe phải là số nguyên ≥ 0.'); // VAL-02.08, BR-10
            }
            $v->throwIfFails();

            $existing = $this->vehicles->mapByApartment($apartmentId, true);
            $old = [];
            $new = [];
            foreach ($types as $type) {
                $quantity = (int) $data['quantity_' . $type];
                $current = isset($existing[$type]) ? (int) $existing[$type]['quantity'] : null;
                if ($current === $quantity || ($current === null && $quantity === 0)) {
                    continue; // không đổi; không tạo dòng 0 thừa cho loại chưa khai báo
                }

                $this->vehicles->setQuantity($apartmentId, $type, $quantity, isset($existing[$type]) ? (int) $existing[$type]['id'] : null);
                $old[$type] = $current ?? 0;
                $new[$type] = $quantity;
            }

            if ($new === []) {
                return false;
            }

            $this->audit('update', 'parking_vehicle', $apartmentId, $old, $new);

            return true;
        });
    }
}
