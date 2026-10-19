<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Đọc input HTTP an toàn kiểu dữ liệu (STEP 8 phần A).
 *
 * Nguyên nhân lỗi cũ: code ép (string) trực tiếp lên $_POST[...]; khi request gửi
 * "username[]=x" giá trị là MẢNG -> PHP Warning "Array to string conversion" ->
 * ErrorHandler trả 500. Lớp này là nơi DUY NHẤT phân biệt:
 *   - input VÔ HƯỚNG (scalar): string()/stringOrNull() - mảng/thiếu => giá trị mặc định,
 *     không bao giờ ném lỗi; isMalformed() cho biết trường có bị gửi sai kiểu không.
 *   - input DANH SÁCH (list): rows() - chỉ nhận mảng các dòng, mỗi ô vô hướng
 *     (vd tiers[i][tier_from] của FN-03).
 * Request sai kiểu được xử lý như dữ liệu không hợp lệ (validation error / 403 CSRF), không 500.
 */
final class Request
{
    /** Giá trị vô hướng đã trim; thiếu hoặc sai kiểu (mảng...) -> $default. */
    /**
     * Thao tác "live" (không tải lại trang): app.js gửi form bằng fetch kèm header X-Live-Request: 1.
     * Server vẫn chạy y hệt (auth, RBAC, CSRF, validate); chỉ khác ở redirect: trả JSON {redirect} thay vì 302.
     */
    public static function isLive(): bool
    {
        return ($_SERVER['HTTP_X_LIVE_REQUEST'] ?? '') === '1' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    public static function postString(string $key, string $default = ''): string
    {
        return self::scalar($_POST, $key) ?? $default;
    }

    /** Giá trị vô hướng KHÔNG trim (mật khẩu: khoảng trắng là một phần dữ liệu); sai kiểu -> $default. */
    public static function postRawString(string $key, string $default = ''): string
    {
        return array_key_exists($key, $_POST) && is_scalar($_POST[$key]) ? (string) $_POST[$key] : $default;
    }

    /** Như postString nhưng thiếu/sai kiểu -> null (để Service báo "bắt buộc"/"không hợp lệ"). */
    public static function postStringOrNull(string $key): ?string
    {
        return self::scalar($_POST, $key);
    }

    public static function queryString(string $key, string $default = ''): string
    {
        return self::scalar($_GET, $key) ?? $default;
    }

    /** POST trước, rồi GET - giữ hành vi BaseController::input() cũ nhưng chỉ trả vô hướng. */
    public static function anyString(string $key, string $default = ''): string
    {
        return self::scalar($_POST, $key) ?? self::scalar($_GET, $key) ?? $default;
    }

    /** true nếu trường CÓ gửi nhưng không phải giá trị vô hướng (vd username[]=x). */
    public static function isMalformed(string $key, string $source = 'post'): bool
    {
        $bag = $source === 'get' ? $_GET : $_POST;

        return array_key_exists($key, $bag) && !is_scalar($bag[$key]) && $bag[$key] !== null;
    }

    /**
     * Input DANH SÁCH dòng: $_POST[$name] phải là mảng các mảng; mỗi ô lấy theo $fields,
     * ô sai kiểu -> ''. Không phải mảng -> [] (Service sẽ báo thiếu dữ liệu).
     * @return array<int, array<string, string>>
     */
    public static function rows(string $name, array $fields): array
    {
        $rows = $_POST[$name] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = [];
            foreach ($fields as $field) {
                $item[$field] = is_scalar($row[$field] ?? null) ? trim((string) $row[$field]) : '';
            }
            $result[] = $item;
        }

        return $result;
    }

    /** Danh sách giá trị vô hướng (vd reading_ids[]=1&reading_ids[]=2); phần tử sai kiểu bị bỏ. */
    public static function postList(string $name): array
    {
        $values = $_POST[$name] ?? [];
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_map(
            static fn ($v): string => trim((string) $v),
            array_filter($values, static fn ($v): bool => is_scalar($v))
        ));
    }

    /**
     * File upload đơn ($_FILES[$name]); upload nhiều file / cấu trúc sai kiểu -> null.
     * @return array{name:string,tmp_name:string,error:int,size:int}|null
     */
    public static function uploadedFile(string $name): ?array
    {
        $file = $_FILES[$name] ?? null;
        if (!is_array($file)) {
            return null;
        }
        foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
            if (!array_key_exists($key, $file) || !is_scalar($file[$key])) {
                return null;
            }
        }

        return ['name' => (string) $file['name'], 'tmp_name' => (string) $file['tmp_name'], 'error' => (int) $file['error'], 'size' => (int) $file['size']];
    }

    private static function scalar(array $bag, string $key): ?string
    {
        if (!array_key_exists($key, $bag) || !is_scalar($bag[$key])) {
            return null;
        }

        return trim((string) $bag[$key]);
    }
}
