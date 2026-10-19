<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Song ngữ giao diện (vi / en). Ngôn ngữ lưu trong session ("locale"), mặc định vi.
 * Từ điển: resources/lang/{vi,en}.php - mảng lồng nhau, tra bằng khoá chấm (vd "menu.dashboard").
 * Thiếu khoá ở en -> dùng vi -> cuối cùng trả lại chính khoá (dễ phát hiện khi thiếu bản dịch).
 * CHỈ dịch nhãn giao diện; dữ liệu nghiệp vụ trong DB (tên, mã, ghi chú...) không đi qua đây.
 */
class Lang
{
    public const SUPPORTED = ['vi', 'en'];
    public const DEFAULT = 'vi';

    /** @var array<string, array> */
    private static array $dictionaries = [];

    /** Ép ngôn ngữ tạm thời (không đổi session), vd phiếu thông báo phí in cho cư dân luôn tiếng Việt. null = theo session. */
    private static ?string $forced = null;

    public static function locale(): string
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        $locale = $_SESSION['locale'] ?? self::DEFAULT;

        return in_array($locale, self::SUPPORTED, true) ? $locale : self::DEFAULT;
    }

    /** Ép ngôn ngữ cho phần đang render (vd đầu view phiếu in), gọi lại với null để trả về theo session. */
    public static function force(?string $locale): void
    {
        self::$forced = $locale !== null && in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }

    public static function setLocale(string $locale): bool
    {
        if (!in_array($locale, self::SUPPORTED, true)) {
            return false;
        }
        Session::put('locale', $locale);

        return true;
    }

    /** @param array<string, string|int|float> $replace thay ":ten" trong bản dịch */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= self::locale();
        $text = self::lookup($locale, $key) ?? ($locale !== self::DEFAULT ? self::lookup(self::DEFAULT, $key) : null) ?? $key;
        // strtr thay MỘT lượt, ưu tiên khoá dài nhất -> ":to" không ăn vào ":total"
        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = (string) $value;
        }

        return $pairs === [] ? $text : strtr($text, $pairs);
    }

    public static function has(string $key, ?string $locale = null): bool
    {
        return self::lookup($locale ?? self::locale(), $key) !== null;
    }

    /**
     * Bản dịch cho chuỗi giao diện trong app.js (khoá phẳng "js.x", "worklist.x", "common.confirm") theo ngôn ngữ hiện tại.
     * Layout CHỈ xuất khi ngôn ngữ khác tiếng Việt (tiếng Việt: app.js dùng chữ gốc) -> trang tiếng Việt không đổi.
     */
    public static function jsDictionary(): array
    {
        $out = [];
        foreach (['js', 'worklist'] as $section) {
            foreach ((array) (self::dictionary(self::locale())[$section] ?? []) as $key => $value) {
                if (is_string($value) && !str_ends_with((string) $key, '_html')) {
                    $out[$section . '.' . $key] = $value;
                }
            }
        }
        $out['common.confirm'] = self::get('common.confirm');

        return $out;
    }

    private static function lookup(string $locale, string $key): ?string
    {
        $value = self::dictionary($locale);
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    private static function dictionary(string $locale): array
    {
        if (!isset(self::$dictionaries[$locale])) {
            $file = BASE_PATH . '/resources/lang/' . $locale . '.php';
            self::$dictionaries[$locale] = is_file($file) ? (array) require $file : [];
        }

        return self::$dictionaries[$locale];
    }
}
