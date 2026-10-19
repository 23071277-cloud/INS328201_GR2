<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validator rule-based dùng chung (server-side - bắt buộc theo mục XIII).
 * Các Validator nghiệp vụ cụ thể (FN-04 MeterReadingValidator, FN-03
 * TariffValidator,...) sẽ kế thừa/sử dụng lớp này ở các STEP module sau.
 * STEP 4 dùng cho form đăng nhập; STEP 6 (FN-02) thêm các rule
 * maxLength/in/pattern/decimal/integer/date và throwIfFails().
 */
class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function required(string $field, ?string $message = null): self
    {
        $value = $this->data[$field] ?? null;

        if ($value === null || trim((string) $value) === '') {
            $this->errors[$field][] = $message ?? "Trường {$field} là bắt buộc.";
        }

        return $this;
    }

    public function minLength(string $field, int $length, ?string $message = null): self
    {
        $value = (string) ($this->data[$field] ?? '');

        if ($value !== '' && mb_strlen($value) < $length) {
            $this->errors[$field][] = $message ?? "Trường {$field} phải có ít nhất {$length} ký tự.";
        }

        return $this;
    }

    public function maxLength(string $field, int $length, ?string $message = null): self
    {
        $value = (string) ($this->data[$field] ?? '');

        if ($value !== '' && mb_strlen($value) > $length) {
            $this->errors[$field][] = $message ?? "Trường {$field} không được dài quá {$length} ký tự.";
        }

        return $this;
    }

    /** Giá trị (nếu có) phải nằm trong danh sách cho phép, ví dụ khoá ENUM ở config/master_data.php. */
    public function in(string $field, array $allowed, ?string $message = null): self
    {
        $value = $this->data[$field] ?? null;

        if ($value !== null && $value !== '' && !in_array((string) $value, array_map('strval', $allowed), true)) {
            $this->errors[$field][] = $message ?? "Giá trị của {$field} không hợp lệ.";
        }

        return $this;
    }

    public function pattern(string $field, string $regex, string $message): self
    {
        $value = (string) ($this->data[$field] ?? '');

        if ($value !== '' && !preg_match($regex, $value)) {
            $this->errors[$field][] = $message;
        }

        return $this;
    }

    /**
     * Số thập phân (dấu chấm), tối đa $scale chữ số sau dấu phẩy, trong khoảng
     * [$min, $max]; $minExclusive = true nghĩa là phải > $min (vd diện tích > 0).
     */
    public function decimal(string $field, int $scale, float $min, float $max, bool $minExclusive, string $message): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value === '') {
            return $this;
        }

        $isNumber = (bool) preg_match('/^-?\d+(\.\d{1,' . $scale . '})?$/', $value);
        $number = (float) $value;
        $tooSmall = $minExclusive ? $number <= $min : $number < $min;

        if (!$isNumber || $tooSmall || $number > $max) {
            $this->errors[$field][] = $message;
        }

        return $this;
    }

    public function integer(string $field, int $min, int $max, string $message): self
    {
        $value = (string) ($this->data[$field] ?? '');

        if ($value !== '' && (!preg_match('/^-?\d+$/', $value) || (int) $value < $min || (int) $value > $max)) {
            $this->errors[$field][] = $message;
        }

        return $this;
    }

    /** Ngày dạng YYYY-MM-DD hợp lệ (input type="date"). */
    public function date(string $field, ?string $message = null): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value === '') {
            return $this;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $this->errors[$field][] = $message ?? "Trường {$field} không phải ngày hợp lệ.";
        }

        return $this;
    }

    /** Thêm lỗi nghiệp vụ do Service tự kiểm tra (trùng mã, cha đã ngừng, ...). */
    public function addError(string $field, string $message): self
    {
        $this->errors[$field][] = $message;

        return $this;
    }

    public function hasError(string $field): bool
    {
        return !empty($this->errors[$field]);
    }

    /** Dùng trong Service: dừng xử lý và trả lỗi về Controller. */
    public function throwIfFails(): void
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
    }

    public function fails(): bool
    {
        return count($this->errors) > 0;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    public function firstErrorOverall(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return $fieldErrors[0];
            }
        }

        return null;
    }
}
