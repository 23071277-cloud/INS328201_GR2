<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\ValidationException;
use App\Services\AuditLogService;

/** FN-01.06 Tra cứu nhật ký audit (STEP 15) - chỉ GET, không có route sửa / xoá log (BR-04). */
class AuditLogController extends BaseController
{
    private AuditLogService $service;

    public function __construct()
    {
        $this->service = new AuditLogService();
    }

    public function index(): void
    {
        $filters = $this->filters(AuditLogService::FILTERS);
        $errors = [];
        $pager = null;
        try {
            $pager = $this->service->search($filters, $this->currentPage());
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        $this->view('audit_logs.index', ['title' => t('page.audit_logs'), 'filters' => $filters, 'pager' => $pager, 'errors' => $errors, 'options' => $this->service->options()]);
    }

    public function show(string $id): void
    {
        $log = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('audit_logs.show', ['title' => 'Bản ghi audit #' . $log['id'], 'log' => $log]);
    }
}
