<?php

declare(strict_types=1);

/**
 * Các ca input sai kiểu cho FN-04 (dùng bởi tests/input_robustness_test.php) - không tạo dữ liệu:
 * mọi request đều phải bị từ chối bằng validation/403/404, không bao giờ 500.
 */
return static function (string $base, callable $jar, callable $req, callable $token, callable $login): array {
    $cases = [];
    $login($base, $jar('bql'), 'bql01');
    $t = $token($jar('bql'), $base . '/periods/create');
    $cases[] = ['FN-04', 'POST /periods month[]/year[]', $req($jar('bql'), 'POST', $base . '/periods', http_build_query(['csrf_token' => $t, 'month' => ['8'], 'year' => ['2099']])), [302]];
    $cases[] = ['FN-04', 'POST /periods/2/approvals reading_ids vô hướng + token[]', $req($jar('bql'), 'POST', $base . '/periods/2/approvals', http_build_query(['csrf_token' => $t, 'reading_ids' => 'abc', 'token_1' => ['x']])), [302]];
    $cases[] = ['FN-04', 'POST /periods/2/approvals reading_ids[][]', $req($jar('bql'), 'POST', $base . '/periods/2/approvals', http_build_query(['csrf_token' => $t, 'reading_ids' => [['1']]])), [302]];
    foreach (['/periods?status[]=mo&year[]=2024', '/readings?period_id[]=1&status[]=nhap&q[]=x&source[]=import', '/periods/2/approvals?flag[]=cl0'] as $path) {
        $cases[] = ['GET', "GET {$path}", $req($jar('bql'), 'GET', $base . $path), [200]];
    }

    $login($base, $jar('kt'), 'kythuat01');
    $t = $token($jar('kt'), $base . '/periods/2/readings/create');
    $cases[] = ['FN-04', 'POST /periods/2/readings current_reading[]', $req($jar('kt'), 'POST', $base . '/periods/2/readings', http_build_query(['csrf_token' => $t, 'meter_id' => '1', 'current_reading' => ['1400']])), [302]];
    $cases[] = ['FN-04', 'POST /periods/2/readings meter_id[]', $req($jar('kt'), 'POST', $base . '/periods/2/readings', http_build_query(['csrf_token' => $t, 'meter_id' => ['1'], 'current_reading' => '1400'])), [302]];
    $cases[] = ['FN-04', 'POST /readings/1/update version_token[] note[]', $req($jar('kt'), 'POST', $base . '/readings/1/update', http_build_query(['csrf_token' => $t, 'current_reading' => '1', 'note' => ['x'], 'version_token' => ['y']])), [302]];
    $cases[] = ['FN-04', 'POST /periods/2/imports file[] (nhiều file / sai cấu trúc)', $req($jar('kt'), 'POST', $base . '/periods/2/imports', http_build_query(['csrf_token' => $t, 'file' => ['x']])), [302]];
    $cases[] = ['FN-04', 'POST /imports/999999/confirm acknowledge_mismatch[]', $req($jar('kt'), 'POST', $base . '/imports/999999/confirm', http_build_query(['csrf_token' => $t, 'acknowledge_mismatch' => ['1']])), [302]];
    $cases[] = ['GET', 'GET /periods/2/readings/create?meter_id[]=1', $req($jar('kt'), 'GET', $base . '/periods/2/readings/create?meter_id[]=1'), [200]];

    return $cases;
};
