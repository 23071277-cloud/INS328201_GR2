<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Env;
use App\Core\ValidationException;
use App\Repositories\SystemConfigRepository;
use Throwable;

/**
 * FN-01.07 Cấu hình tham số hệ thống - chỉ các khoá trong whitelist config/system_parameters.php (V8).
 * get(): đọc system_configs 1 lần / request (bộ nhớ đệm tĩnh) -> sửa có hiệu lực từ request sau, không cần khởi động lại
 * Apache; DB lỗi hoặc giá trị lưu không hợp lệ -> mặc định (.env / whitelist). Sửa: transaction + FOR UPDATE + version token
 * + audit UPDATE_SYSTEM_PARAMETER (khoá, cũ, mới). Không có thêm / xoá khoá trên giao diện.
 */
class SystemParameterService extends BaseService
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    private SystemConfigRepository $configs;

    public function __construct()
    {
        $this->configs = new SystemConfigRepository();
    }

    /** Giá trị hiệu lực của 1 tham số whitelist (chuỗi đã kiểm hợp lệ). */
    public static function get(string $key): string
    {
        $def = config('system_parameters')[$key] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException("Tham số không thuộc whitelist: {$key}");
        }
        if (self::$cache === null) {
            try {
                self::$cache = (new SystemConfigRepository())->allValues();
            } catch (Throwable $e) {
                self::$cache = []; // DB chưa sẵn sàng -> dùng mặc định
            }
        }
        $stored = self::$cache[$key] ?? null;
        if ($stored !== null && self::check($def, $stored) === null) {
            return $stored;
        }
        $env = $def['env'] !== null ? Env::get($def['env']) : null;

        return $env !== null && self::check($def, (string) $env) === null ? (string) $env : (string) $def['default'];
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /** Xoá bộ nhớ đệm (sau khi sửa trong cùng request / test). */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /** Danh sách whitelist kèm giá trị lưu + giá trị hiệu lực. */
    public function list(): array
    {
        $rows = array_column($this->configs->allRows(), null, 'config_key');
        $out = [];
        foreach (config('system_parameters') as $key => $def) {
            $row = $rows[$key] ?? null;
            $out[] = ['key' => $key, 'def' => $def, 'row' => $row, 'effective' => self::get($key),
                'valid' => $row !== null && self::check($def, (string) $row['config_value']) === null];
        }

        return $out;
    }

    /** Chỉ khoá whitelist; khoá khác (kể cả có trong bảng) -> null (404). */
    public function find(int $id): ?array
    {
        $row = $this->configs->findDetailed($id);
        $def = $row !== null ? (config('system_parameters')[$row['config_key']] ?? null) : null;

        return $def === null ? null : ['key' => $row['config_key'], 'def' => $def, 'row' => $row, 'version_token' => self::versionToken($row)];
    }

    public function update(int $id, string $value, string $token): void
    {
        $this->transaction($this->configs, function () use ($id, $value, $token): void {
            $row = $this->configs->findForUpdate($id);
            $def = $row !== null ? (config('system_parameters')[$row['config_key']] ?? null) : null;
            if ($def === null) {
                throw ValidationException::withMessage('Tham số không tồn tại hoặc không được sửa trên giao diện.');
            }
            if ($token !== self::versionToken($row)) {
                throw ValidationException::withMessage('Tham số đã bị người khác thay đổi sau khi bạn mở form. Vui lòng tải lại trang.');
            }
            $value = trim($value);
            $error = self::check($def, $value);
            if ($error !== null) {
                throw ValidationException::withMessage($error, 'config_value');
            }
            if ($def['type'] === 'integer') {
                $value = (string) (int) $value; // "05" -> "5"
            }
            if ($value === (string) $row['config_value']) {
                throw ValidationException::withMessage('Giá trị không thay đổi.', 'config_value');
            }
            $this->configs->updateById($id, ['config_value' => $value, 'updated_by' => $this->actorId()]);
            $this->audit('UPDATE_SYSTEM_PARAMETER', 'system_config', $id, ['key' => $row['config_key'], 'value' => $row['config_value']],
                ['key' => $row['config_key'], 'value' => $value]);
        });
        self::flush();
    }

    public static function versionToken(array $row): string
    {
        return sha1(implode('|', [$row['id'], $row['config_key'], $row['config_value'], $row['updated_at']]));
    }

    /** null = hợp lệ; ngược lại là thông báo lỗi. */
    public static function check(array $def, string $value): ?string
    {
        switch ($def['type']) {
            case 'integer':
                if (!preg_match('/^\d{1,9}$/', $value)) {
                    return 'Giá trị phải là số nguyên dương.';
                }
                if ((int) $value < $def['min'] || (int) $value > $def['max']) {
                    return "Giá trị phải trong khoảng {$def['min']} – {$def['max']}.";
                }

                return null;
            case 'enum':
                return array_key_exists($value, $def['values']) ? null : 'Giá trị không thuộc danh sách cho phép.';
            case 'string':
                $len = mb_strlen($value);
                if ($len < $def['min'] || $len > $def['max']) {
                    return "Độ dài phải từ {$def['min']} đến {$def['max']} ký tự.";
                }

                return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) ? 'Không được chứa ký tự điều khiển.' : null;
            default:
                return 'Kiểu tham số không hỗ trợ.';
        }
    }
}
