<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\BillingPeriodService;
use App\Services\ReadingImportService;

/** FN-04.03 Import chỉ số từ Excel (upload -> preview -> xác nhận/huỷ) + FN-04.05 control total. */
class ImportController extends BaseController
{
    private ReadingImportService $service;
    private BillingPeriodService $periods;

    public function __construct()
    {
        $this->service = new ReadingImportService();
        $this->periods = new BillingPeriodService();
    }

    public function create(string $id): void
    {
        $period = $this->periods->find($this->routeId($id)) ?? $this->notFound();

        $this->view('imports.create', [
            'title' => 'Import chỉ số — kỳ ' . $period['period_code'],
            'period' => $period,
            'strict' => $this->service->isStrictMode(),
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(string $id): void
    {
        $periodId = $this->routeId($id);
        $file = Request::uploadedFile('file');
        if ($file !== null && $file['error'] === UPLOAD_ERR_OK && !is_uploaded_file($file['tmp_name'])) {
            $file = null; // chỉ chấp nhận file thực sự được upload qua HTTP POST
        }

        try {
            $batchId = $this->service->upload($periodId, $file);
        } catch (ValidationException $e) {
            $this->backWithErrors("/periods/{$periodId}/imports/create", $e, []);
        }

        Flash::info('Đã kiểm tra file. Xem trước kết quả bên dưới — chưa có chỉ số nào được lưu cho tới khi bạn xác nhận.');
        $this->redirect('/imports/' . $batchId);
    }

    public function show(string $id): void
    {
        $batch = $this->service->preview($this->routeId($id)) ?? $this->notFound();

        $this->view('imports.show', [
            'title' => 'Lô import #' . $batch['id'],
            'batch' => $batch,
            'strict' => $this->service->isStrictMode(),
        ]);
    }

    public function confirm(string $id): void
    {
        $batchId = $this->routeId($id);

        try {
            $result = $this->service->confirm($batchId, Request::postString('acknowledge_mismatch') === '1');
            Flash::success("Đã lưu {$result['saved']} chỉ số (Nháp, chờ BQL duyệt)." . ($result['skipped'] > 0 ? " {$result['skipped']} dòng lỗi không được lưu." : ''));
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/imports/' . $batchId);
    }

    public function cancel(string $id): void
    {
        $batchId = $this->routeId($id);

        try {
            $this->service->cancel($batchId);
            Flash::success('Đã hủy lô import. Không có chỉ số nào được lưu.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/imports/' . $batchId);
    }

    /** Tải file mẫu .xlsx của kỳ (CSĐ hệ thống điền sẵn) - tạo tạm rồi xoá ngay. */
    public function template(string $id): void
    {
        $period = $this->periods->find($this->routeId($id)) ?? $this->notFound();
        // File tạm trong storage/imports (thư mục đã cấp quyền ghi cho Apache - README mục 11), tên ngẫu nhiên.
        $tmp = ReadingImportService::storageDir() . '/template_' . bin2hex(random_bytes(8)) . '.xlsx';
        $this->service->buildTemplate((int) $period['id'], $tmp);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="mau_chi_so_' . str_replace('/', '-', $period['period_code']) . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        header('X-Content-Type-Options: nosniff');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
