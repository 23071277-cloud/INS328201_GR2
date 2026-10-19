<?php

declare(strict_types=1);

namespace App\Support;

use SimpleXMLElement;
use ZipArchive;

/**
 * Đọc sheet ĐẦU TIÊN của file .xlsx (Office Open XML) - FN-04.03.
 * Không dùng thư viện ngoài: chỉ ext-zip + ext-simplexml (có sẵn trong PHP của XAMPP).
 * Hỗ trợ: chuỗi dùng chung (sharedStrings), inline string, số, bool, công thức có giá trị cache.
 * Không hỗ trợ: .xls (BIFF), nhiều sheet (chỉ đọc sheet 1), ô gộp, định dạng ngày.
 * An toàn: giới hạn số entry/tổng dung lượng giải nén (chống zip bomb), giới hạn số dòng,
 * không bật LIBXML_NOENT (không mở rộng entity), LIBXML_NONET (không tải tài nguyên mạng).
 */
final class XlsxReader
{
    private const MAX_ENTRIES = 200;
    private const MAX_UNCOMPRESSED = 50 * 1024 * 1024;
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @return array<int, array<int, string>> số dòng Excel (1-based) => [chỉ số cột 0-based => giá trị chuỗi]
     */
    public static function firstSheetRows(string $path, int $maxRows): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new XlsxException('File không phải Excel .xlsx hợp lệ (không đọc được gói OOXML).');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new XlsxException('File Excel có cấu trúc bất thường (quá nhiều thành phần).');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            if ($total > self::MAX_UNCOMPRESSED) {
                throw new XlsxException('File Excel quá lớn sau khi giải nén.');
            }

            $workbook = self::xml($zip, 'xl/workbook.xml');
            if ($workbook === null || !isset($workbook->sheets->sheet[0])) {
                throw new XlsxException('File không phải Excel .xlsx hợp lệ (thiếu workbook/sheet).');
            }
            $relId = (string) ($workbook->sheets->sheet[0]->attributes(self::NS_REL)['id'] ?? '');
            $sheetPath = self::sheetPath($zip, $relId);
            $sheet = self::xml($zip, $sheetPath);
            if ($sheet === null) {
                throw new XlsxException('Không đọc được sheet đầu tiên của file Excel.');
            }

            return self::rows($sheet, self::sharedStrings($zip), $maxRows);
        } finally {
            $zip->close();
        }
    }

    private static function sheetPath(ZipArchive $zip, string $relId): string
    {
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        if ($rels !== null) {
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $relId) {
                    $target = ltrim((string) $rel['Target'], '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** @return string[] */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $strings = [];
        foreach ($xml->si as $si) {
            $strings[] = self::text($si);
        }

        return $strings;
    }

    private static function rows(SimpleXMLElement $sheet, array $shared, int $maxRows): array
    {
        $rows = [];
        $nextRow = 1;
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $rowNo = isset($row['r']) ? (int) $row['r'] : $nextRow;
            $nextRow = $rowNo + 1;
            if (count($rows) >= $maxRows + 5) {
                throw new XlsxException("File có quá nhiều dòng (tối đa {$maxRows} dòng dữ liệu).");
            }

            $cells = [];
            $nextCol = 0;
            foreach ($row->c as $c) {
                $col = isset($c['r']) ? self::columnIndex((string) $c['r']) : $nextCol;
                $nextCol = $col + 1;
                $type = (string) ($c['t'] ?? 'n');
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default => (string) ($c->v ?? ''),
                };
                if (($type === 'n' || $type === '') && $value !== '' && is_numeric($value)) {
                    $value = self::normalizeNumber($value);
                }
                $cells[$col] = trim($value);
            }
            $rows[$rowNo] = $cells;
        }

        return $rows;
    }

    /** "343.00000000000006" / "1.343E3" -> "343" / "1343" (2 chữ số thập phân, bỏ 0 thừa). */
    private static function normalizeNumber(string $value): string
    {
        $formatted = number_format(round((float) $value, 2), 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            $text = (string) $node->t;
            if (!isset($node->r)) {
                return $text;
            }
        }
        $text = '';
        foreach ($node->r ?? [] as $run) {
            $text .= (string) $run->t;
        }

        return $text !== '' ? $text : (string) ($node->t ?? '');
    }

    /** "B3" -> 1; "AA10" -> 26. */
    public static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? 'A';
        $index = 0;
        foreach (str_split($letters) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }

        return max(0, $index - 1);
    }

    private static function xml(ZipArchive $zip, string $name): ?SimpleXMLElement
    {
        $content = $zip->getFromName($name);
        if ($content === false || $content === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }
}
