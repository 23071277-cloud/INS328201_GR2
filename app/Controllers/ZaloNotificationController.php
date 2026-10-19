<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\ZaloNotificationService;

/** Gửi phiếu thông báo phí qua Zalo (ZNS) + Hộp thư Zalo. Controller chỉ đọc input và gọi Service; quyền do route (RbacMiddleware) quyết định. */
class ZaloNotificationController extends BaseController
{
    private ZaloNotificationService $service;

    public function __construct()
    {
        $this->service = new ZaloNotificationService();
    }

    /** POST /charges/{id}/zalo */
    public function send(string $id): void
    {
        $chargeId = $this->routeId($id);
        if (!$this->service->available()) {
            Flash::error('Chưa cài đặt chức năng Zalo trên cơ sở dữ liệu này (cần chạy migration 20261018_zalo_notifications.sql).');
            $this->redirect('/charges/' . $chargeId . '/notice');
        }
        try {
            $r = $this->service->sendBillNotice($chargeId, Request::postString('resident_id'));
            if ($r['status'] === 'sent') {
                Flash::success($this->service->driver() === 'zns'
                    ? 'Đã gửi phiếu phí qua Zalo.'
                    : 'Đã ghi nhận tin Zalo (chế độ demo "' . $this->service->driver() . '": không gửi ra ngoài).');
            } else {
                Flash::error('Gửi Zalo không thành công: ' . $r['error'] . ' — có thể "Gửi lại" trong Hộp thư Zalo.');
            }
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/charges/' . $chargeId . '/notice');
    }

    public function index(): void
    {
        if (!$this->service->available()) {
            $this->view('zalo_notifications.index', ['title' => 'Hộp thư Zalo', 'filters' => [], 'pager' => null, 'errors' => [], 'unavailable' => true]);

            return;
        }
        $filters = $this->filters(['status', 'date_from', 'date_to', 'recipient', 'apartment', 'period_id']);
        $errors = [];
        $pager = null;
        try {
            $pager = $this->service->search($filters, $this->currentPage());
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        $this->view('zalo_notifications.index', ['title' => 'Hộp thư Zalo', 'filters' => $filters, 'pager' => $pager, 'errors' => $errors, 'unavailable' => false]);
    }

    public function show(string $id): void
    {
        $this->service->available() || $this->notFound();
        $n = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('zalo_notifications.show', ['title' => 'Tin Zalo #' . $n['id'], 'n' => $n]);
    }

    /** POST /zalo-notifications/{id}/retry */
    public function retry(string $id): void
    {
        $this->service->available() || $this->notFound();
        $notificationId = $this->routeId($id);
        try {
            $r = $this->service->retry($notificationId);
            $r['status'] === 'sent' ? Flash::success('Đã gửi lại tin Zalo thành công.') : Flash::error('Gửi lại không thành công: ' . $r['error']);
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/zalo-notifications/' . $notificationId);
    }
}
