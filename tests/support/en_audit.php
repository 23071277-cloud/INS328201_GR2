<?php

declare(strict_types=1);

/** Final i18n audit (dùng bởi tests/i18n_test.php): duyệt trang tiếng Anh, đoạn chữ tiếng Việt chỉ được phép nếu là dữ liệu lưu DB hoặc ngoại lệ đã duyệt (B / mã hệ thống). */
function en_audit_db_values(PDO $pdo, string $db): array
{
    $vals = [];
    $cols = $pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = " . $pdo->quote($db)
        . " AND data_type IN ('varchar','text','mediumtext','longtext','char','json','tinytext')")->fetchAll(PDO::FETCH_NUM);
    foreach ($cols as [$t, $c]) {
        foreach ($pdo->query("SELECT DISTINCT `$c` FROM `$t` WHERE `$c` REGEXP '[^ -~]' LIMIT 5000")->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $v = (string) $v;
            $vals[$v] = true;
            if ($v !== '' && ($v[0] === '{' || $v[0] === '[')) { // JSON (audit, snapshot): thêm từng chuỗi lá
                $d = json_decode($v, true);
                if (is_array($d)) {
                    array_walk_recursive($d, static function ($x) use (&$vals) { if (is_string($x)) { $vals[$x] = true; } });
                }
            }
        }
    }
    $vals = array_map('strval', array_keys($vals)); // khoá số (vd "12") bị PHP đổi sang int
    usort($vals, static fn ($a, $b) => strlen($b) <=> strlen($a));

    return array_values(array_filter($vals, static fn ($v) => mb_strlen($v) >= 2));
}

function en_audit_segments(string $html): array
{
    $html = preg_replace(['#<(script|style)\b[^>]*>.*?</\1>#si', '#<!--.*?-->#s'], ' ', $html);
    $segs = [];
    preg_match_all('/\s(title|placeholder|aria-label|alt|data-confirm-title|data-confirm-message|data-confirm-button)="([^"]*)"/', $html, $mm, PREG_SET_ORDER);
    foreach ($mm as $a) {
        $segs[] = html_entity_decode($a[2], ENT_QUOTES, 'UTF-8');
    }
    foreach (explode("\n", preg_replace('/<[^>]+>/', "\n", $html)) as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', html_entity_decode($line, ENT_QUOTES, 'UTF-8')));
        if ($line !== '') {
            $segs[] = $line;
        }
    }

    return $segs;
}

/** Phần còn lại sau khi bỏ dữ liệu DB + ngoại lệ đã duyệt; trả về chuỗi nếu vẫn còn chữ tiếng Việt, null nếu sạch */
function en_audit_residue(string $seg, array $dbValues, array $allowed): ?string
{
    $vi = '/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu';
    if (!preg_match($vi, $seg)) {
        return null;
    }
    $rest = $seg;
    foreach ($allowed as $a) {
        $rest = str_replace($a, ' ', $rest);
    }
    foreach ($dbValues as $v) {
        if (mb_strlen(trim($rest)) >= 2 && str_contains($v, trim($rest))) {
            return null; // nguyên đoạn là một phần của giá trị DB (vd 1 dòng nội dung email đã lưu)
        }
    }
    $trunc = preg_match('/^(.{20,})…$/su', trim($rest), $tm) ? $tm[1] : null; // giá trị DB bị cắt ngắn để hiển thị (audit JSON...)
    foreach ($dbValues as $v) {
        if (str_contains($rest, $v)) {
            $rest = str_replace($v, ' ', $rest);
        } elseif ($trunc !== null && str_starts_with($v, $trunc)) {
            return null;
        }
    }
    $rest = preg_replace('/(?<![\p{L}])đ(?![\p{L}])/u', ' ', $rest); // ký hiệu tiền tệ đ (đ, đ/m², 1.000 đ)
    if (!preg_match($vi, $rest)) {
        return null;
    }
    foreach ($dbValues as $v) {
        if (str_contains($v, trim($rest)) && mb_strlen(trim($rest)) >= 2) {
            return null; // một phần của giá trị DB (vd 1 dòng của nội dung email đã lưu)
        }
        if (str_contains($rest, $v)) {
            $rest = str_replace($v, ' ', $rest);
            if (!preg_match($vi, $rest)) {
                return null;
            }
        }
    }

    return preg_match($vi, $rest) ? $seg : null;
}

function en_audit_allowed(string $path = ''): array
{
    $file = str_contains($path, '/imports') // tên cột / dòng tổng của file Excel - người dùng phải gõ đúng (mã định dạng file)
        ? array_merge(config('readings.import.headers'), config('readings.import.optional_headers'), ['"Tổng"', '"Tổng cộng"']) : [];
    usort($file, static fn ($a, $b) => strlen($b) <=> strlen($a));

    return array_merge($file, [
        'Tiếng Việt',                     // tên ngôn ngữ trong nút chuyển (endonym)
        '[ĐX]', 'ĐX',                     // mã nguồn tài liệu (đề xuất) - mã hệ thống
        'Mã công tơ',                     // tên cột Excel người dùng phải gõ đúng
        (string) config('j16.tech_reason'), // B: lý do kiểm tra J-16 (cũng là nội dung case lưu DB)
        (string) config('billing.reconnection_fee.note'), // B: ghi chú snapshot phiếu
        'Tổng phải thu',                  // B: tiền tố mô tả audit tính lại phí (billing/show)
    ]);
}
