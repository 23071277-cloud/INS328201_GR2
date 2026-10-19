<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Lỗi gửi Zalo đã làm sạch (không chứa token / secret) - an toàn để lưu và hiển thị. */
class ZaloTransportException extends RuntimeException
{
}
