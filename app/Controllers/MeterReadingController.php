<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\BillingPeriodService;
use App\Services\MeterReadingService;
use App\Services\ZoneService;

/** FN-04.02 Nhập tay, FN-04.07 Sửa, FN-04.09 Tra cứu & chi tiết chỉ số. */
class MeterReadingController extends BaseController
{
    private MeterReadingService $service;
    private BillingPeriodService $periods;

    public function __construct()
    {
        $this->service = new MeterReadingService();
        $this->periods = new BillingPeriodService();
    }

    public function index(): void
    {
        $filters = $this->filters(['period_id', 'zone_id', 'q', 'meter_code', 'utility_type', 'status', 'source']);

        $this->view('readings.index', [
            'title' => t('page.readings'),
            'filters' => $filters,
            'pager' => $this->service->list($filters, $this->currentPage()),
            'periodOptions' => $this->periods->options(),
            'zoneOptions' => (new ZoneService())->options(false),
        ]);
    }

    /**
     * Nhập tay (UX worklist): danh sách mọi công tơ của kỳ kèm trạng thái ghi số + panel nhập cho công tơ đang chọn (?meter_id=).
     * Không có meter_id -> chọn công tơ "Chưa ghi" nhập được đầu tiên. Lọc ?q=&status=&utility= chỉ là trạng thái hiển thị.
     */
    public function create(string $id): void
    {
        $period = $this->periods->find($this->routeId($id)) ?? $this->notFound();
        $query = $this->filters(['meter_id', 'q', 'status', 'utility']);
        $worklist = $this->service->worklist($period);
        $meterId = ctype_digit($query['meter_id']) ? (int) $query['meter_id'] : 0;
        if ($meterId === 0 && $query['meter_id'] === '') {
            $meterId = $this->service->nextPendingMeterId($period, 0) ?? 0;
        }
        $context = $meterId > 0 ? $this->service->entryContext((int) $period['id'], $meterId) : null;

        $this->view('readings.create', [
            'title' => 'Nhập chỉ số — kỳ ' . $period['period_code'],
            'period' => $period,
            'worklist' => $worklist,
            'context' => $context,
            'listFilters' => $this->listFilters($query),
            'doneAll' => $query['meter_id'] === 'none',
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(string $id): void
    {
        $periodId = $this->routeId($id);
        $input = $this->only(['meter_id', 'current_reading', 'note']);
        $meterId = ctype_digit((string) $input['meter_id']) ? (int) $input['meter_id'] : 0;
        $keep = $this->listFilters($this->only(['q', 'status', 'utility']));
        $page = static function (array $extra) use ($periodId, $keep): string {
            $query = array_filter($keep + $extra, static fn ($v): bool => $v !== '' && $v !== null);

            return "/periods/{$periodId}/readings/create" . ($query === [] ? '' : '?' . http_build_query($query));
        };

        try {
            $result = $this->service->create($periodId, $meterId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors($meterId > 0 ? $page(['meter_id' => $meterId]) : $page([]), $e, $input);
        }

        Flash::success('Đã lưu chỉ số Nháp. Tiêu thụ CL = ' . format_decimal($result['consumption']) . ' — chờ BQL duyệt.');
        // "Lưu & nhập công tơ tiếp theo": chọn công tơ Chưa ghi kế tiếp (không chọn công tơ đã ghi/đã duyệt); "Lưu nháp": ở lại công tơ vừa lưu
        if ($this->only(['next'])['next'] === '1') {
            $period = $this->periods->find($periodId);
            $next = $period !== null ? $this->service->nextPendingMeterId($period, $meterId) : null;
            $this->redirect($page(['meter_id' => $next ?? 'none']));
        }
        $this->redirect($page(['meter_id' => $meterId]));
    }

    /** Bộ lọc worklist hợp lệ (whitelist) để giữ qua redirect; giá trị lạ bị bỏ. */
    private function listFilters(array $raw): array
    {
        $q = trim((string) ($raw['q'] ?? ''));

        return [
            'q' => mb_substr($q, 0, 100),
            'status' => array_key_exists((string) ($raw['status'] ?? ''), config('readings.worklist_status')) ? (string) $raw['status'] : '',
            'utility' => in_array($raw['utility'] ?? '', ['dien', 'nuoc'], true) ? (string) $raw['utility'] : '',
        ];
    }

    public function show(string $id): void
    {
        $reading = $this->service->detail($this->routeId($id)) ?? $this->notFound();

        $this->view('readings.show', ['title' => 'Chỉ số #' . $reading['id'], 'reading' => $reading]);
    }

    public function edit(string $id): void
    {
        $reading = $this->service->find($this->routeId($id)) ?? $this->notFound();
        if ($reading['period_status'] === 'da_khoa') {
            Flash::error('Kỳ đã khóa, không thể thay đổi chỉ số.');
            $this->redirect('/readings/' . $reading['id']);
        }

        $this->view('readings.edit', ['title' => 'Sửa chỉ số #' . $reading['id'], 'reading' => $reading, 'form' => Flash::pullFormState()]);
    }

    public function update(string $id): void
    {
        $readingId = $this->routeId($id);
        $input = $this->only(['current_reading', 'note', 'version_token']);

        try {
            $changed = $this->service->update($readingId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors("/readings/{$readingId}/edit", $e, $input);
        }

        $changed ? Flash::success('Đã cập nhật chỉ số. Bản ghi ở trạng thái Nháp, chờ BQL duyệt lại.') : Flash::info('Không có thay đổi nào.');
        $this->redirect('/readings/' . $readingId);
    }
}
