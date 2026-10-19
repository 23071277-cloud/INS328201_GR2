<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\FixedFeeRepository;

/**
 * FN-03.02 Quản lý phí cố định & phí gửi xe | [TL] 2.1, 2.3; BR-15, BR-16, BR-17, BR-19, BR-10; [ĐX] BR-42.
 * - Mỗi loại phí (ENUM fee_type) là một chuỗi phiên bản theo ngày hiệu lực (P14) - đơn giá lấy từ DB.
 * - Thêm = phiên bản mới; sửa chỉ phiên bản Sắp áp dụng (P3) với version token (bảng không có updated_at).
 * - KHÔNG có đề xuất phí cố định: B9/J-06 [NEED BA CONFIRMATION].
 */
class FixedFeeService extends BaseService
{
    private FixedFeeRepository $fees;
    private BillingPeriodRepository $periods;

    public function __construct()
    {
        $this->fees = new FixedFeeRepository();
        $this->periods = new BillingPeriodRepository();
    }

    /**
     * Tổng quan theo loại phí: phiên bản đang áp dụng, sắp áp dụng, lịch sử.
     * @return array<string, array{current:?array,upcoming:array,history:array}>
     */
    public function overview(string $feeType = ''): array
    {
        $feeType = array_key_exists($feeType, config('pricing.fee_type')) ? $feeType : '';
        $groups = [];
        foreach (array_keys(config('pricing.fee_type')) as $type) {
            if ($feeType === '' || $feeType === $type) {
                $groups[$type] = ['current' => null, 'upcoming' => [], 'history' => []];
            }
        }

        foreach ($this->fees->listWithLifecycle($this->today(), $feeType) as $row) {
            $row['edit_block_reason'] = $this->editBlockReason($row);
            $groups[$row['fee_type']]['history'][] = $row;
            if ($row['lifecycle'] === 'dang_ap_dung') {
                $groups[$row['fee_type']]['current'] = $row;
            } elseif ($row['lifecycle'] === 'sap_ap_dung') {
                $groups[$row['fee_type']]['upcoming'][] = $row;
            }
        }

        return $groups;
    }

    public function find(int $id): ?array
    {
        $row = $this->fees->find($id);
        if ($row !== null) {
            $row['version_token'] = $this->versionToken($row);
            $row['edit_block_reason'] = $this->editBlockReason($row);
        }

        return $row;
    }

    public function editBlockReason(array $row): ?string
    {
        if ($row['status'] !== 'hieu_luc') {
            return 'Phiên bản đã hết hiệu lực.';
        }
        if ($row['effective_date'] <= $this->today()) {
            return 'Đơn giá đã/đang được áp dụng — không sửa đè lịch sử. Hãy thêm đơn giá mới với ngày hiệu lực mới.';
        }
        $lastLocked = $this->periods->lastLockedEndDate();
        if ($lastLocked !== null && $row['effective_date'] <= $lastLocked) {
            return 'Ngày hiệu lực thuộc kỳ đã khóa.';
        }

        return null;
    }

    public function create(array $input): int
    {
        $data = $this->validate($input, true);

        return $this->transaction($this->fees, function () use ($data): int {
            $this->fees->lockSeries($data['fee_type']);
            $this->assertEffectiveDateAllowed($data['fee_type'], $data['effective_date'], null);

            $id = $this->fees->insertRow($data + ['status' => 'hieu_luc', 'created_by' => $this->actorId()]);
            $this->audit('CREATE_FIXED_FEE', 'fixed_fee_rate', $id, null, $this->fees->find($id));

            return $id;
        });
    }

    public function update(int $id, array $input): bool
    {
        return $this->transaction($this->fees, function () use ($id, $input): bool {
            // STEP 15: khoá chuỗi loại phí trước rồi mới khoá dòng (cùng thứ tự với create) - xem tests/fn03_lock_order_demo.sh.
            $series = $this->fees->find($id);
            if ($series === null) {
                throw ValidationException::withMessage('Đơn giá không tồn tại.');
            }
            $this->fees->lockSeries($series['fee_type']);
            $current = $this->fees->findForUpdate($id);
            if ($current === null) {
                throw ValidationException::withMessage('Đơn giá không tồn tại.');
            }

            $reason = $this->editBlockReason($current);
            if ($reason !== null) {
                throw ValidationException::withMessage('Không thể sửa đơn giá này: ' . $reason);
            }
            if (($input['version_token'] ?? '') !== $this->versionToken($current)) {
                throw ValidationException::withMessage('Dữ liệu đã bị người khác thay đổi sau khi bạn mở form. Vui lòng tải lại trang và nhập lại.');
            }

            $data = $this->validate($input + ['fee_type' => $current['fee_type']], false);
            $this->assertEffectiveDateAllowed($current['fee_type'], $data['effective_date'], $id);

            $changes = $this->diff($current, ['unit_price' => $data['unit_price'], 'effective_date' => $data['effective_date']]);
            if ($changes === []) {
                return false;
            }

            $this->fees->updateById($id, $changes);
            $this->audit('UPDATE_FIXED_FEE', 'fixed_fee_rate', $id, array_intersect_key($current, $changes), $changes);

            return true;
        });
    }

    public function versionToken(array $row): string
    {
        return sha1(json_encode([$row['id'], $row['fee_type'], (string) $row['unit_price'], $row['effective_date'], $row['status']]));
    }

    /** @return array{fee_type:string,unit_price:string,effective_date:string} */
    private function validate(array $input, bool $isCreate): array
    {
        $data = [
            'fee_type' => (string) ($input['fee_type'] ?? ''),
            'unit_price' => $this->normalizeDecimal($input['unit_price'] ?? ''),
            'effective_date' => (string) ($input['effective_date'] ?? ''),
        ];

        $v = new Validator($data);
        if ($isCreate) {
            $v->required('fee_type', 'Vui lòng chọn loại phí.')
                ->in('fee_type', array_keys(config('pricing.fee_type')), 'Loại phí không hợp lệ.');
        }
        $v->required('unit_price', 'Vui lòng nhập đơn giá.')
            ->decimal('unit_price', 2, 0, 9999999999.99, true, 'Đơn giá phải lớn hơn 0.') // VAL-03.01, BR-10
            ->required('effective_date', 'Vui lòng chọn ngày hiệu lực.')
            ->date('effective_date', 'Ngày hiệu lực không hợp lệ.');
        $v->throwIfFails();

        $data['unit_price'] = number_format((float) $data['unit_price'], 2, '.', '');

        return $data;
    }

    /** VAL-03.04 / BR-42 (P4) + không trùng ngày hiệu lực trong cùng loại phí (P5). */
    private function assertEffectiveDateAllowed(string $feeType, string $date, ?int $exceptId): void
    {
        $lastLocked = $this->periods->lastLockedEndDate();
        if ($lastLocked !== null && $date <= $lastLocked) {
            throw new ValidationException(['effective_date' => [sprintf(
                'Không thể áp dụng đơn giá cho kỳ đã khóa. Kỳ khóa gần nhất kết thúc %s — ngày hiệu lực phải sau ngày này.',
                date('d/m/Y', (int) strtotime($lastLocked))
            )]]);
        }
        if ($this->fees->effectiveDateExists($feeType, $date, $exceptId)) {
            throw new ValidationException(['effective_date' => ['Loại phí này đã có đơn giá hiệu lực từ ' . date('d/m/Y', (int) strtotime($date)) . '.']]);
        }
    }

    private function today(): string
    {
        return date('Y-m-d');
    }
}
