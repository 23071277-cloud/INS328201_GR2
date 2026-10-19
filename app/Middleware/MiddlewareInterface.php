<?php

declare(strict_types=1);

namespace App\Middleware;

interface MiddlewareInterface
{
    /**
     * Trả về true để Router tiếp tục dispatch tới Controller.
     * Trả về false (hoặc tự exit) khi middleware đã tự xử lý response
     * (redirect về /login, trả 403) và request phải dừng tại đây.
     */
    public function handle(?string $param = null): bool;
}
