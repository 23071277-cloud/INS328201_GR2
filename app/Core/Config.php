<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Đọc toàn bộ file trong config/*.php (mỗi file trả về 1 mảng) và cho phép
 * truy vấn bằng khoá dạng "file.key" (ví dụ: "app.name") hoặc
 * "file.key.with.dots" (ví dụ: "permissions.dashboard.view" - khớp nguyên văn
 * khoá "dashboard.view" trong config/permissions.php).
 */
class Config
{
    private static array $items = [];
    private static bool $loaded = false;

    private static function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }

        $files = glob(BASE_PATH . '/config/*.php') ?: [];
        foreach ($files as $file) {
            $key = basename($file, '.php');
            self::$items[$key] = require $file;
        }

        self::$loaded = true;
    }

    public static function get(string $key, $default = null)
    {
        self::loadAll();

        $firstDot = strpos($key, '.');
        if ($firstDot === false) {
            return self::$items[$key] ?? $default;
        }

        $file = substr($key, 0, $firstDot);
        $rest = substr($key, $firstDot + 1);

        if (!array_key_exists($file, self::$items)) {
            return $default;
        }

        $data = self::$items[$file];

        // Khớp nguyên văn trước (hỗ trợ permissions.php dùng khoá "module.action")
        if (is_array($data) && array_key_exists($rest, $data)) {
            return $data[$rest];
        }

        // Nếu không khớp nguyên văn, duyệt dần theo từng đoạn (hỗ trợ mảng lồng nhau)
        $value = $data;
        foreach (explode('.', $rest) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Ghi đè một khoá cấp 2 ("file.key") trong bộ nhớ của tiến trình hiện tại - KHÔNG ghi file.
     * Dùng cho test các nhánh cấu hình chưa được BA chốt (vd billing.fire_insurance, J-08).
     */
    public static function set(string $key, $value): void
    {
        self::loadAll();
        [$file, $rest] = array_pad(explode('.', $key, 2), 2, null);
        if ($rest === null) {
            self::$items[$file] = $value;

            return;
        }
        self::$items[$file][$rest] = $value;
    }
}
