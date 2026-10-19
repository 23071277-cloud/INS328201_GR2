<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;

/**
 * Trang "Hướng dẫn sử dụng": các bước làm việc theo từng vai trò, bằng ngôn ngữ đời thường. Chỉ đọc, mọi vai trò đã đăng nhập đều xem được.
 */
class HelpController extends BaseController
{
    public function index(): void
    {
        $this->view('help.index', ['title' => t('page.help')]);
    }
}
