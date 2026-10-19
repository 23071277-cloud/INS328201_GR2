<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Service ném khi dữ liệu không hợp lệ hoặc vi phạm business rule.
 * $errors: ['tên_trường' => ['thông báo', ...]]; lỗi chung dùng khoá '_'.
 * Controller bắt exception này, lưu lỗi + input cũ rồi redirect về form.
 */
class ValidationException extends RuntimeException
{
    /** @var array<string, string[]> */
    private array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct(self::firstMessage($errors) ?? 'Dữ liệu không hợp lệ.');
    }

    public static function withMessage(string $message, string $field = '_'): self
    {
        return new self([$field => [$message]]);
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        return $this->errors;
    }

    private static function firstMessage(array $errors): ?string
    {
        foreach ($errors as $messages) {
            if (!empty($messages)) {
                return (string) $messages[0];
            }
        }

        return null;
    }
}
