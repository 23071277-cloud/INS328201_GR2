<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\MeterReadingRepository;

/**
 * FN-04.08 Phê duyệt dữ liệu chỉ số | [TL] 2.3 "Chỉ số công tơ: BQL View, Approve"; 2.4 chỉ dữ liệu
 * đã xác nhận được dùng (BR-11). Duyệt từng bản hoặc theo lô ([ĐX], J-13).
 *
 * An toàn: một transaction, khoá từng bản (SELECT ... FOR UPDATE) - bản nào không hợp lệ thì
 * BỎ QUA kèm lý do (lô có xung đột từng phần), bản khác vẫn duyệt:
 *   - đã được duyệt (2 BQL duyệt cùng lúc / duyệt lại) ;
 *   - đã bị sửa sau khi BQL mở màn hình (version token = hash nội dung) - "duyệt trong lúc sửa";
 *   - không thuộc kỳ / kỳ đã khoá.
 * "Trả lại" (Nháp + cờ cần sửa + lý do) KHÔNG làm: schema không có cột cờ/lý do - BLOCKED B6/J-13.
 */
class ReadingApprovalService extends BaseService
{
    private MeterReadingRepository $readings;
    private BillingPeriodRepository $periods;

    public function __construct()
    {
        $this->readings = new MeterReadingRepository();
        $this->periods = new BillingPeriodRepository();
    }

    public function drafts(int $periodId, bool $zeroOnly): array
    {
        return array_map(static fn (array $r): array => $r + ['version_token' => MeterReadingService::versionToken($r)],
            $this->readings->draftsOfPeriod($periodId, $zeroOnly));
    }

    /**
     * @param array<int|string, string> $items reading_id => version token lúc BQL xem (MeterReadingService::versionToken)
     * @return array{approved: int[], skipped: array<int, string>}
     */
    public function approve(int $periodId, array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessage('Vui lòng chọn ít nhất một chỉ số để duyệt.');
        }

        $result = $this->transaction($this->readings, function () use ($periodId, $items): array {
            $period = $this->periods->findForUpdate($periodId);
            if ($period === null) {
                throw ValidationException::withMessage('Kỳ ghi chỉ số không tồn tại.');
            }
            if ($period['status'] === 'da_khoa') {
                throw ValidationException::withMessage('Kỳ đã khóa, không thể duyệt chỉ số.');
            }

            $approved = [];
            $skipped = [];
            $now = date('Y-m-d H:i:s');
            ksort($items); // khoá theo thứ tự id cố định -> tránh deadlock giữa 2 lô
            foreach ($items as $id => $token) {
                $id = (int) $id;
                $reading = $this->readings->findForUpdate($id);
                if ($reading === null || (int) $reading['billing_period_id'] !== $periodId) {
                    $skipped[$id] = 'Không tồn tại trong kỳ này.';
                    continue;
                }
                if ($reading['status'] !== 'nhap') {
                    $skipped[$id] = 'Đã được duyệt trước đó.';
                    continue;
                }
                if ((string) $token !== MeterReadingService::versionToken($reading)) {
                    $skipped[$id] = 'Đã bị sửa sau khi bạn mở màn hình duyệt — vui lòng tải lại để xem số mới.';
                    continue;
                }

                $this->readings->updateById($id, ['status' => 'da_xac_nhan', 'confirmed_by' => $this->actorId(), 'confirmed_at' => $now]);
                $this->audit('APPROVE_METER_READING', 'meter_reading', $id,
                    ['status' => 'nhap'],
                    ['status' => 'da_xac_nhan', 'confirmed_at' => $now, 'current_reading' => $reading['current_reading'], 'consumption' => $reading['consumption']]
                );
                $approved[] = $id;
            }

            return ['approved' => $approved, 'skipped' => $skipped];
        });

        // [BA Proposal J-16] sau khi commit: đánh giá căn khai báo "không phát sinh" có CL đã xác nhận > 0 (lỗi J-16 không làm hỏng duyệt)
        if ($result['approved'] !== []) {
            $result['j16'] = (new NoUsageConsumptionService())->evaluateQuietly($periodId, 'approve_readings');
        }

        return $result;
    }
}
