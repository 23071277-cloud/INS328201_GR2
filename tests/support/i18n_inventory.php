<?php

declare(strict_types=1);

/**
 * Kiểm kê chuỗi giao diện tiếng Việt còn viết cứng (chưa qua t()) - phục vụ chuyển song ngữ theo từng đợt.
 *   php tests/support/i18n_inventory.php [--files]
 * Đếm theo token PHP (bỏ comment): đoạn HTML trong view có chữ tiếng Việt + chuỗi PHP có chữ tiếng Việt. Ước lượng, không phải test.
 */
$root = dirname(__DIR__, 2);
$vn = '/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu';
$areas = [
    'Views (resources/views)' => 'resources/views',
    'Controllers (tiêu đề, flash)' => 'app/Controllers',
    'Services (thông báo lỗi / kiểm tra)' => 'app/Services',
    'Config (nhãn ENUM / trạng thái)' => 'config',
    'Helpers' => 'app/Helpers',
    'Core (validator, lỗi chung)' => 'app/Core',
];
$skip = ['resources/views/emails/']; // email gửi cư dân: ngoài phạm vi giao diện web
$perFile = [];
$summary = [];
foreach ($areas as $label => $dir) {
    $files = 0;
    $strings = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($root) + 1);
        if ($file->getExtension() !== 'php' || array_filter($skip, static fn ($s) => str_starts_with($rel, $s)) !== []) {
            continue;
        }
        $n = 0;
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $tok) {
            if (!is_array($tok) || in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($tok[0] === T_INLINE_HTML) {
                // đoạn chữ hiển thị giữa các thẻ HTML
                foreach (preg_split('/<[^>]*>/', $tok[1]) as $chunk) {
                    if (preg_match($vn, $chunk)) {
                        $n++;
                    }
                }
                // thuộc tính hiển thị (placeholder / title / aria-label)
                $n += preg_match_all('/(?:placeholder|title|aria-label)="[^"]*' . substr($vn, 1, -3) . '[^"]*"/iu', $tok[1]);
            } elseif (in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match($vn, $tok[1])) {
                $n++;
            }
        }
        if ($n > 0) {
            $files++;
            $strings += $n;
            $perFile[$rel] = $n;
        }
    }
    $summary[$label] = [$files, $strings];
}
printf("%-40s %6s %8s\n", 'Khu vực', 'File', 'Chuỗi');
foreach ($summary as $label => [$f, $s]) {
    printf("%-40s %6d %8d\n", $label, $f, $s);
}
printf("%-40s %6d %8d\n", 'TỔNG', array_sum(array_column($summary, 0)), array_sum(array_column($summary, 1)));
if (in_array('--files', $argv, true)) {
    arsort($perFile);
    echo "\n";
    foreach ($perFile as $f => $n) {
        printf("%5d  %s\n", $n, $f);
    }
}
