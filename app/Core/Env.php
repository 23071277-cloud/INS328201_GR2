<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Đọc file .env tối giản (key=value), không phụ thuộc thư viện ngoài (không Composer).
 */
class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (!str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                // Biến môi trường thật của tiến trình được ưu tiên hơn .env (quy ước dotenv) - dùng để chạy test với DB khác
                // (vd DB_DATABASE=energy_dss_v2) mà KHÔNG sửa .env. Apache / production không đặt các biến này -> vẫn đọc .env.
                $real = getenv($name);
                if ($real === false) {
                    putenv("{$name}={$value}");
                }
                $_ENV[$name] = $real === false ? $value : $real;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? getenv($key);

        return $value === false ? $default : $value;
    }
}
