<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** STEP 25 - lỗi gửi email; thông báo đã được EmailService::sanitize() làm sạch (an toàn để lưu last_error / hiển thị). */
class EmailTransportException extends RuntimeException
{
}
