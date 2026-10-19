<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\UserService;

/**
 * FN-01.03 Quản lý tài khoản (STEP 15). Quyền do route (RbacMiddleware) quyết định - không kiểm role ở đây.
 * Mật khẩu: đọc nguyên văn (postRawString), KHÔNG đưa vào form state / flash / log.
 */
class UserController extends BaseController
{
    private UserService $service;

    public function __construct()
    {
        $this->service = new UserService();
    }

    public function index(): void
    {
        $filters = $this->filters(['q', 'role', 'status']);

        $this->view('users.index', ['title' => t('page.users'), 'filters' => $filters, 'pager' => $this->service->search($filters, $this->currentPage())]);
    }

    public function show(string $id): void
    {
        $user = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('users.show', ['title' => 'Tài khoản ' . $user['username'], 'u' => $user, 'logs' => can('audit_logs.view') ? $this->service->recentAudit((int) $user['id']) : null]);
    }

    public function create(): void
    {
        $this->view('users.form', ['title' => 'Thêm tài khoản', 'u' => null, 'form' => Flash::pullFormState()]);
    }

    public function store(): void
    {
        $input = ['username' => Request::postString('username'), 'full_name' => Request::postString('full_name'), 'role' => Request::postString('role')];
        try {
            $id = $this->service->create($input, Request::postRawString('password'), Request::postRawString('password_confirmation'));
            Flash::success('Đã tạo tài khoản. Hãy giao mật khẩu khởi tạo cho người dùng qua kênh riêng.');
            $this->redirect('/users/' . $id);
        } catch (ValidationException $e) {
            $this->backWithErrors('/users/create', $e, $input); // không giữ lại mật khẩu
        }
    }

    public function edit(string $id): void
    {
        $user = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('users.form', ['title' => 'Sửa tài khoản ' . $user['username'], 'u' => $user, 'form' => Flash::pullFormState()]);
    }

    public function update(string $id): void
    {
        $userId = $this->routeId($id);
        $input = ['full_name' => Request::postString('full_name'), 'role' => Request::postString('role')];
        try {
            $this->service->update($userId, $input, Request::postString('version_token'));
            Flash::success('Đã cập nhật tài khoản. Vai trò mới có hiệu lực từ request kế tiếp của người dùng.');
            $this->redirect('/users/' . $userId);
        } catch (ValidationException $e) {
            $this->backWithErrors("/users/{$userId}/edit", $e, $input);
        }
    }

    public function status(string $id): void
    {
        $userId = $this->routeId($id);
        try {
            $this->service->changeStatus($userId, Request::postString('action'), Request::postString('version_token'));
            Flash::success('Đã đổi trạng thái tài khoản. Phiên đang mở của tài khoản bị vô hiệu hóa sẽ bị thu hồi ở request kế tiếp.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/users/' . $userId);
    }

    public function passwordForm(string $id): void
    {
        $user = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('users.password', ['title' => 'Đặt lại mật khẩu ' . $user['username'], 'u' => $user, 'form' => Flash::pullFormState()]);
    }

    public function password(string $id): void
    {
        $userId = $this->routeId($id);
        try {
            $this->service->resetPassword($userId, Request::postRawString('password'), Request::postRawString('password_confirmation'), Request::postString('version_token'));
            Flash::success('Đã đặt lại mật khẩu. Các phiên đăng nhập khác của tài khoản này đã bị thu hồi.');
            $this->redirect('/users/' . $userId);
        } catch (ValidationException $e) {
            $this->backWithErrors("/users/{$userId}/password", $e, []);
        }
    }
}
