<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\EmailNotificationService;

/**
 * STEP 25 - FN-10.03 gửi thông báo phí qua email + hộp thư đi. Controller chỉ đọc input và gọi Service
 * (Controller → EmailNotificationService → EmailService → SMTP); quyền do route (RbacMiddleware) quyết định.
 */
class EmailNotificationController extends BaseController
{
    private EmailNotificationService $service;

    public function __construct()
    {
        $this->service = new EmailNotificationService();
    }

    /** POST /charges/{id}/email */
    public function send(string $id): void
    {
        $chargeId = $this->routeId($id);
        try {
            $r = $this->service->sendBillNotice($chargeId, Request::postString('resident_id'));
            if ($r['status'] === 'sent') {
                Flash::success(config('mail.driver') === 'smtp'
                    ? 'Đã gửi email thông báo phí.'
                    : 'Đã ghi nhận email thông báo phí (chế độ demo "' . config('mail.driver') . '": không gửi ra Internet).');
            } else {
                Flash::error('Gửi email không thành công: ' . $r['error'] . ' — có thể "Gửi lại" trong Hộp thư đi.');
            }
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/charges/' . $chargeId . '/notice');
    }

    public function index(): void
    {
        $filters = $this->filters(['status', 'date_from', 'date_to', 'recipient', 'apartment', 'period_id']);
        $errors = [];
        $pager = null;
        try {
            $pager = $this->service->search($filters, $this->currentPage());
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        $this->view('email_notifications.index', ['title' => t('page.outbox'), 'filters' => $filters, 'pager' => $pager, 'errors' => $errors]);
    }

    public function show(string $id): void
    {
        $n = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('email_notifications.show', ['title' => 'Email #' . $n['id'], 'n' => $n]);
    }

    /** POST /email-notifications/{id}/retry */
    public function retry(string $id): void
    {
        $notificationId = $this->routeId($id);
        try {
            $r = $this->service->retry($notificationId);
            $r['status'] === 'sent' ? Flash::success('Đã gửi lại email thành công.') : Flash::error('Gửi lại không thành công: ' . $r['error']);
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/email-notifications/' . $notificationId);
    }
}
