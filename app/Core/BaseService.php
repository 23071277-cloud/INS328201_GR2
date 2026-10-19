<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Base cho các Service nghiệp vụ (STEP 6 trở đi).
 * - transaction(): thay đổi dữ liệu + ghi audit trong CÙNG một transaction
 *   ([ĐX] FN-01.05: ghi log thất bại thì huỷ thao tác gốc).
 * - assertNotStale(): chống ghi đè khi 2 người cùng sửa ([ĐX] FN-02 "dữ liệu đã
 *   bị người khác sửa -> từ chối lưu và yêu cầu tải lại").
 */
abstract class BaseService
{
    /**
     * [TECHNICAL ASSUMPTION D12] Mã danh mục (tòa, khu, căn, công tơ): chữ/số và . _ - /,
     * không khoảng trắng. Tài liệu chỉ cho ví dụ LK2, S01, BT4, 2305 (2.1).
     */
    protected const CODE_REGEX = '/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/';
    protected const CODE_MESSAGE = 'Mã chỉ gồm chữ, số và các ký tự . _ - / (không có khoảng trắng).';

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function transaction(BaseRepository $repository, callable $callback)
    {
        // Lồng nhau (vd FN-07.09 gọi FN-04 MeterReadingService::update): chạy trong transaction ngoài,
        // lỗi ném ra để transaction ngoài ROLLBACK toàn bộ. PDO không hỗ trợ BEGIN lồng.
        if ($repository->inTransaction()) {
            return $callback();
        }
        $repository->beginTransaction();

        try {
            $result = $callback();
            $repository->commit();

            return $result;
        } catch (Throwable $e) {
            $repository->rollBack();
            throw $e;
        }
    }

    protected function assertNotStale(array $current, ?string $submittedUpdatedAt): void
    {
        if ($submittedUpdatedAt === null || $submittedUpdatedAt !== (string) $current['updated_at']) {
            throw ValidationException::withMessage(
                'Dữ liệu đã bị người khác thay đổi sau khi bạn mở form. Vui lòng tải lại trang và nhập lại.'
            );
        }
    }

    /** [TL] NFR-05 / BR-04: ai, khi nào, đối tượng, giá trị cũ - mới. */
    protected function audit(string $action, string $entityType, int $entityId, ?array $old, ?array $new): void
    {
        AuditLogger::log($action, $entityType, (string) $entityId, $old, $new);
    }

    protected function actorId(): ?int
    {
        $user = auth_user();

        return $user['id'] ?? null;
    }

    /** Các trường thực sự đổi giữa bản ghi hiện tại và dữ liệu mới (so sánh dạng chuỗi). */
    protected function diff(array $current, array $changes): array
    {
        $diff = [];
        foreach ($changes as $key => $value) {
            $old = $current[$key] ?? null;
            if ($old === null && $value === null) {
                continue;
            }
            if ($old === null || $value === null || (string) $old !== (string) $value) {
                $diff[$key] = $value;
            }
        }

        return $diff;
    }

    /** Chuỗi rỗng -> null (cho cột nullable). */
    protected function nullIfEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /** "80,5" -> "80.5" khi người dùng gõ dấu phẩy thập phân kiểu Việt Nam. */
    protected function normalizeDecimal(?string $value): string
    {
        $value = str_replace(' ', '', (string) $value);
        if (!str_contains($value, '.') && substr_count($value, ',') === 1) {
            $value = str_replace(',', '.', $value);
        }

        return $value;
    }

    protected function isPositiveInt(?string $value): bool
    {
        return $value !== null && ctype_digit($value) && (int) $value > 0;
    }
}
