<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Callback Mock Bank bị từ chối: thông báo an toàn để trả về + mã HTTP (404 tắt, 401 sai chữ ký, 400/422 payload, 503 chưa migrate). */
class PaymentCallbackException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
