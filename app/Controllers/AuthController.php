<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Services\AuthService;

class AuthController extends BaseController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function showLogin(): void
    {
        if (auth_user() !== null) {
            $this->redirect('/dashboard');
        }

        $this->view('auth.login', ['title' => 'Đăng nhập'], 'layouts.auth');
    }

    public function login(): void
    {
        // Request trả '' khi trường thiếu HOẶC bị gửi sai kiểu (vd username[]=x) -> VAL-01.01, không 500.
        $username = $this->input('username');
        $password = Request::postRawString('password'); // không trim mật khẩu (giữ hành vi cũ)

        if ($username === '' || $password === '') {
            // VAL-01.01
            Flash::error('Vui lòng nhập tên đăng nhập và mật khẩu.');
            Flash::setOldInput(['username' => $username]);
            $this->redirect('/login');
        }

        $result = $this->authService->attemptLogin($username, $password);

        if (!$result['success']) {
            Flash::error((string) $result['message']);
            Flash::setOldInput(['username' => $username]);
            $this->redirect('/login');
        }

        Flash::success('Đăng nhập thành công.');
        $this->redirect('/dashboard');
    }

    public function logout(): void
    {
        $this->authService->logout();
        Flash::success('Bạn đã đăng xuất.');
        $this->redirect('/login');
    }
}
