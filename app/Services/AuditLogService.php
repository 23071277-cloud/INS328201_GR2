<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;

/**
 * FN-01.06 Tra cứu nhật ký audit (STEP 15, V7) - CHỈ ĐỌC (BR-04): không có thao tác sửa / xoá log.
 * Giá trị cũ / mới hiển thị qua mask(): khoá nhạy cảm (mật khẩu, token, secret, session, csrf) -> "***".
 */
class AuditLogService
{
    public const PER_PAGE = 50;
    public const FILTERS = ['user_id', 'action', 'entity_type', 'entity_id', 'date_from', 'date_to', 'ip_address'];

    private AuditLogRepository $logs;

    public function __construct()
    {
        $this->logs = new AuditLogRepository();
    }

    /** Bộ lọc sai định dạng -> ValidationException (không truy vấn). */
    public function search(array $filters, int $page): array
    {
        $this->validate($filters);
        $result = $this->logs->search($filters, $page, self::PER_PAGE);
        foreach ($result['data'] as &$row) {
            $row = $this->present($row);
        }

        return $result;
    }

    public function find(int $id): ?array
    {
        $row = $this->logs->findDetailed($id);

        return $row === null ? null : $this->present($row);
    }

    public function options(): array
    {
        return [
            'actions' => $this->logs->distinctValues('action'),
            'entity_types' => $this->logs->distinctValues('entity_type'),
            'users' => (new UserRepository())->search([], 1, 200)['data'],
        ];
    }

    public function validate(array $f): void
    {
        $errors = [];
        foreach (['date_from', 'date_to'] as $k) {
            if (($f[$k] ?? '') !== '' && !self::isDate($f[$k])) {
                $errors[$k][] = 'Ngày không hợp lệ (định dạng YYYY-MM-DD).';
            }
        }
        if (!isset($errors['date_from']) && !isset($errors['date_to']) && ($f['date_from'] ?? '') !== '' && ($f['date_to'] ?? '') !== '' && $f['date_from'] > $f['date_to']) {
            $errors['date_to'][] = 'Ngày bắt đầu phải nhỏ hơn hoặc bằng ngày kết thúc.'; // [ĐX] FN-01.06 VAL
        }
        if (($f['user_id'] ?? '') !== '' && !ctype_digit($f['user_id'])) {
            $errors['user_id'][] = 'Người thực hiện không hợp lệ.';
        }
        if (($f['ip_address'] ?? '') !== '' && filter_var($f['ip_address'], FILTER_VALIDATE_IP) === false) {
            $errors['ip_address'][] = 'Địa chỉ IP không hợp lệ.';
        }
        foreach (['action' => 100, 'entity_type' => 100, 'entity_id' => 50] as $k => $max) {
            if (mb_strlen($f[$k] ?? '') > $max) {
                $errors[$k][] = "Tối đa {$max} ký tự.";
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /** Giải mã JSON + che khoá nhạy cảm (đệ quy). */
    private function present(array $row): array
    {
        foreach (['old_value', 'new_value'] as $k) {
            $decoded = $row[$k] !== null ? json_decode((string) $row[$k], true) : null;
            $row[$k] = is_array($decoded) ? self::mask($decoded) : $decoded;
        }

        return $row;
    }

    public static function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::mask($value);
            }
        }

        return $data;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $k = strtolower($key);
        if ($k === 'version_token' || $k === 'password_reset') {
            return false; // token chống ghi đè / cờ "đã đặt lại" - không bí mật
        }

        return (bool) preg_match('/pass(word)?|pwd|token|secret|session|csrf|api_?key|app_key/', $k);
    }

    private static function isDate(string $value): bool
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
