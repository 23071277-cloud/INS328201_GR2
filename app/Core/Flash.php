<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Flash message qua Session + giữ lại input cũ khi validation lỗi
 * (yêu cầu mục XII: "giữ lại dữ liệu user nhập khi validation lỗi").
 */
class Flash
{
    public static function success(string $message): void
    {
        self::push('success', $message);
    }

    public static function error(string $message): void
    {
        self::push('error', $message);
    }

    public static function info(string $message): void
    {
        self::push('info', $message);
    }

    private static function push(string $type, string $message): void
    {
        $messages = Session::get('flash_' . $type, []);
        $messages[] = $message;
        Session::put('flash_' . $type, $messages);
    }

    /** @return string[] */
    public static function get(string $type): array
    {
        $messages = Session::get('flash_' . $type, []);
        Session::remove('flash_' . $type);

        return $messages;
    }

    public static function setOldInput(array $input): void
    {
        Session::put('old_input', $input);
    }

    public static function old(string $key, string $default = ''): string
    {
        $old = Session::get('old_input', []);

        return (string) ($old[$key] ?? $default);
    }

    public static function clearOldInput(): void
    {
        Session::remove('old_input');
    }

    /**
     * FN-02+: lỗi validation theo trường + input cũ cho MỘT lần hiển thị form.
     * Khác setOldInput(): trạng thái bị xoá ngay khi form đọc (pullFormState),
     * nên không rò dữ liệu sang form khác ở lần mở sau.
     */
    public static function setFormState(array $errors, array $oldInput): void
    {
        unset($oldInput['csrf_token']);
        Session::put('form_state', ['errors' => $errors, 'old' => $oldInput]);
    }

    /** @return array{errors: array<string, string[]>, old: array<string, mixed>} */
    public static function pullFormState(): array
    {
        $state = Session::get('form_state', ['errors' => [], 'old' => []]);
        Session::remove('form_state');

        return [
            'errors' => (array) ($state['errors'] ?? []),
            'old' => (array) ($state['old'] ?? []),
        ];
    }
}
