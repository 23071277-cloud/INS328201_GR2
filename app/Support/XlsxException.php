<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** File .xlsx không đọc được / sai cấu trúc OOXML. Thông báo hiển thị được cho người dùng. */
final class XlsxException extends RuntimeException
{
}
