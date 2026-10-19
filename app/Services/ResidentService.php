<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\ApartmentRepository;
use App\Repositories\ResidentRepository;

/**
 * FN-02.04 Quản lý chủ hộ/khách thuê | [TL] FR-03 (VN), 2.1; NFR-06, BR-36.
 * Chỉ thu thập đúng các trường schema có: họ tên, vai trò, SĐT (nếu có),
 * thời gian hiệu lực, trạng thái hiệu lực. Không thêm trường cá nhân khác.
 * Quyền xem SĐT: [TECHNICAL ASSUMPTION D10] chỉ BQL - [NEED BA CONFIRMATION NFR-06/BR-36].
 */
class ResidentService extends BaseService
{
    private ResidentRepository $residents;
    private ApartmentRepository $apartments;

    public function __construct()
    {
        $this->residents = new ResidentRepository();
        $this->apartments = new ApartmentRepository();
    }

    public function find(int $id): ?array
    {
        return $this->residents->find($id);
    }

    public function create(int $apartmentId, array $input): int
    {
        return $this->transaction($this->residents, function () use ($apartmentId, $input): int {
            $apartment = $this->apartments->find($apartmentId);
            if ($apartment === null) {
                throw ValidationException::withMessage('Căn hộ không tồn tại.');
            }
            if ($apartment['status'] !== 'hoat_dong') {
                // D5 [TECHNICAL ASSUMPTION]
                throw ValidationException::withMessage('Căn hộ đã ngừng hoạt động, không thể thêm chủ hộ/khách thuê.');
            }

            $data = $this->validate($input);
            $id = $this->residents->insertRow($data + ['apartment_id' => $apartmentId, 'is_active' => 1]);
            $this->audit('create', 'resident', $id, null, $this->residents->find($id));

            return $id;
        });
    }

    public function update(int $id, array $input): bool
    {
        return $this->transaction($this->residents, function () use ($id, $input): bool {
            $current = $this->lockOrFail($id);
            $this->assertNotStale($current, $input['updated_at'] ?? null);

            $changes = $this->diff($current, $this->validate($input));
            if ($changes === []) {
                return false;
            }

            $this->residents->updateById($id, $changes);
            $this->audit('update', 'resident', $id, array_intersect_key($current, $changes), $changes);

            return true;
        });
    }

    /** D11: hết hiệu lực = is_active 0, effective_to = hôm nay nếu chưa có ngày kết thúc sớm hơn. */
    public function deactivate(int $id): int
    {
        return $this->transaction($this->residents, function () use ($id): int {
            $current = $this->lockOrFail($id);
            if ((int) $current['is_active'] === 0) {
                throw ValidationException::withMessage('Chủ hộ/khách thuê đã hết hiệu lực.');
            }

            $today = date('Y-m-d');
            $effectiveTo = $current['effective_to'];
            if ($effectiveTo === null || $effectiveTo > $today) {
                $effectiveTo = max($today, (string) ($current['effective_from'] ?? $today));
            }

            $changes = ['is_active' => 0, 'effective_to' => $effectiveTo];
            $this->residents->updateById($id, $changes);
            $this->audit('deactivate', 'resident', $id, array_intersect_key($current, $changes), $changes);

            return (int) $current['apartment_id'];
        });
    }

    private function lockOrFail(int $id): array
    {
        $row = $this->residents->findForUpdate($id);
        if ($row === null) {
            throw ValidationException::withMessage('Chủ hộ/khách thuê không tồn tại.');
        }

        return $row;
    }

    private function validate(array $input): array
    {
        $data = [
            'full_name' => (string) ($input['full_name'] ?? ''),
            'resident_role' => (string) ($input['resident_role'] ?? ''),
            'phone' => (string) ($input['phone'] ?? ''),
            'email' => strtolower((string) ($input['email'] ?? '')),
            'effective_from' => (string) ($input['effective_from'] ?? ''),
            'effective_to' => (string) ($input['effective_to'] ?? ''),
        ];

        $v = new Validator($data);
        $v->required('full_name', 'Vui lòng nhập họ tên.') // [ĐX] FN-02.04
            ->maxLength('full_name', 150, 'Họ tên tối đa 150 ký tự.')
            ->required('resident_role', 'Vui lòng chọn vai trò.')
            ->in('resident_role', array_keys(config('master_data.resident_role')), 'Vai trò không hợp lệ.')
            ->maxLength('phone', 20, 'Số điện thoại tối đa 20 ký tự.')
            ->pattern('phone', '/^[0-9+().\s-]{6,20}$/', 'Số điện thoại chỉ gồm chữ số và + ( ) . - (6–20 ký tự).') // D12
            ->maxLength('email', 255, 'Email tối đa 255 ký tự.')
            ->date('effective_from', 'Ngày bắt đầu hiệu lực không hợp lệ.')
            ->date('effective_to', 'Ngày kết thúc hiệu lực không hợp lệ.');

        if (!$v->hasError('effective_from') && !$v->hasError('effective_to')
            && $data['effective_from'] !== '' && $data['effective_to'] !== ''
            && $data['effective_to'] < $data['effective_from']) {
            $v->addError('effective_to', 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.');
        }

        // STEP 25 (FN-10.03, A-EMAIL-01): email không bắt buộc; nếu có phải hợp lệ và không chứa ký tự xuống dòng (chống header injection)
        if ($data['email'] !== '' && !$v->hasError('email')
            && (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $data['email']))) {
            $v->addError('email', 'Email không hợp lệ.');
        }

        $v->throwIfFails();

        return [
            'full_name' => $data['full_name'],
            'resident_role' => $data['resident_role'],
            'phone' => $this->nullIfEmpty($data['phone']),
            'email' => $this->nullIfEmpty($data['email']),
            'effective_from' => $this->nullIfEmpty($data['effective_from']),
            'effective_to' => $this->nullIfEmpty($data['effective_to']),
        ];
    }
}
