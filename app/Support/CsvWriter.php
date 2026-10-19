<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Xuất CSV an toàn cho Excel (U7): UTF-8 có BOM, dấu phẩy, RFC 4180 (quote/escape/xuống dòng do fputcsv),
 * chống CSV formula injection: ô VĂN BẢN bắt đầu bằng = + - @ tab CR được thêm dấu ' ở đầu. Ô số giữ nguyên.
 */
final class CsvWriter
{
    public const BOM = "\xEF\xBB\xBF";

    /** Ô văn bản do người dùng nhập (mã căn, tên khu, họ tên…). */
    public static function text(?string $value): string
    {
        $value = (string) $value;
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/u', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    /** Ô số (chuỗi DECIMAL hệ thống sinh) - không thêm ', giữ dấu - của số âm. */
    public static function number(?string $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /** @param iterable<array<int,string>> $rows -> chuỗi CSV hoàn chỉnh (có BOM). */
    public static function build(array $header, iterable $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, self::BOM);
        fputcsv($h, $header, ',', '"', '', "\r\n");
        foreach ($rows as $row) {
            fputcsv($h, $row, ',', '"', '', "\r\n");
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);

        return $csv;
    }
}
