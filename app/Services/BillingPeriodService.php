<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ImportBatchRepository;

/**
 * FN-04.01 Tạo kỳ ghi chỉ số | [TL] T2 Workflow 3.1, UC-01; VAL-04.01; G4 (kỳ mới = 'mo').
 * Actor: [ASSUMPTION J-10] BQL. Khoá/mở lại kỳ thuộc FN-05.07/05.08 - không có ở đây.
 * "Sinh danh sách công tơ cần ghi số" được tính động (pendingMeters) vì schema không có
 * bảng danh sách chờ và current_reading NOT NULL; CSĐ xác định lúc nhập (MeterReadingService).
 */
class BillingPeriodService extends BaseService
{
    private BillingPeriodRepository $periods;

    public function __construct()
    {
        $this->periods = new BillingPeriodRepository();
    }

    public function list(array $filters, int $page): array
    {
        $filters['status'] = array_key_exists($filters['status'], config('readings.period_status')) ? $filters['status'] : '';
        $filters['year'] = preg_match('/^\d{4}$/', $filters['year']) ? $filters['year'] : '';

        return $this->periods->search($filters, $page, (int) config('master_data.per_page', 20));
    }

    public function years(): array
    {
        return $this->periods->years();
    }

    public function options(): array
    {
        return $this->periods->options();
    }

    public function find(int $id): ?array
    {
        return $this->periods->findDetailed($id);
    }

    public function detail(int $id): ?array
    {
        $period = $this->periods->findDetailed($id);
        if ($period === null) {
            return null;
        }
        $period['stats'] = $this->periods->stats($id);
        $period['pending'] = $this->periods->pendingMeters($id);
        $period['active_meters'] = $this->periods->activeMeters($id); // UI: danh sách công tơ đang hoạt động + trạng thái chỉ số (chỉ đọc)
        $period['imports'] = (new ImportBatchRepository())->listByPeriod($id);
        $period['previous'] = $this->periods->previousOf((string) $period['start_date']);

        return $period;
    }

    /** Giá trị mặc định cho form: tháng kế tiếp kỳ mới nhất (hoặc tháng hiện tại). */
    public function suggestion(): array
    {
        $latest = $this->periods->options()[0] ?? null;
        $start = $latest !== null ? new \DateTimeImmutable($latest['start_date'] . ' +1 month') : new \DateTimeImmutable('first day of this month');

        return ['month' => $start->format('n'), 'year' => $start->format('Y')];
    }

    /** @return array{id:int,warning:?string} */
    public function create(array $input): array
    {
        $data = [
            'month' => (string) ($input['month'] ?? ''),
            'year' => (string) ($input['year'] ?? ''),
            'start_date' => (string) ($input['start_date'] ?? ''),
            'end_date' => (string) ($input['end_date'] ?? ''),
            'reading_due_date' => (string) ($input['reading_due_date'] ?? ''),
        ];

        $v = new Validator($data);
        $v->required('month', 'Vui lòng chọn tháng.')->integer('month', 1, 12, 'Tháng không hợp lệ.')
            ->required('year', 'Vui lòng nhập năm.')->integer('year', 2000, 2100, 'Năm không hợp lệ (2000–2100).')
            ->date('start_date', 'Ngày bắt đầu không hợp lệ.')
            ->date('end_date', 'Ngày kết thúc không hợp lệ.')
            ->date('reading_due_date', 'Hạn nhập chỉ số không hợp lệ.');
        $v->throwIfFails();

        // [ĐX] Mã kỳ MM/YYYY; ngày mặc định = đầu/cuối tháng nếu để trống.
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', (int) $data['year'], (int) $data['month']));
        $code = $first->format('m/Y');
        $start = $data['start_date'] !== '' ? $data['start_date'] : $first->format('Y-m-d');
        $end = $data['end_date'] !== '' ? $data['end_date'] : $first->format('Y-m-t');
        $due = $this->nullIfEmpty($data['reading_due_date']);

        if ($end < $start) {
            $v->addError('end_date', 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.');
        }
        if ($due !== null && $due < $start) {
            $v->addError('reading_due_date', 'Hạn nhập chỉ số phải từ ngày bắt đầu kỳ trở đi.');
        }
        $v->throwIfFails();

        return $this->transaction($this->periods, function () use ($code, $start, $end, $due): array {
            if ($this->periods->codeExists($code)) {
                throw new ValidationException(['month' => ['Kỳ này đã được tạo.']]); // VAL-04.01
            }
            $overlap = $this->periods->overlapping($start, $end);
            if ($overlap !== null) {
                throw new ValidationException(['start_date' => ["Khoảng ngày giao với kỳ {$overlap['period_code']} đã có."]]);
            }

            $id = $this->periods->insertRow([
                'period_code' => $code,
                'start_date' => $start,
                'end_date' => $end,
                'reading_due_date' => $due,
                'status' => 'mo', // G4: (chưa có) -> Mở
                'created_by' => $this->actorId(),
            ]);
            $this->audit('CREATE_BILLING_PERIOD', 'billing_period', $id, null, $this->periods->find($id));

            // [ĐX] FN-04.01 mục 4: cảnh báo (không chặn) nếu kỳ trước chưa khoá
            $previous = $this->periods->previousOf($start);
            $warning = $previous !== null && $previous['status'] !== 'da_khoa'
                ? "Kỳ trước ({$previous['period_code']}) chưa khóa. Chỉ số kỳ trước còn Nháp sẽ chưa dùng làm CSĐ được."
                : null;

            return ['id' => $id, 'warning' => $warning];
        });
    }
}
