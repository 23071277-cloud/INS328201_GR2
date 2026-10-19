<?php

declare(strict_types=1);

/**
 * Test ZaloClient (không cần database, không ra Internet): chuẩn hoá SĐT, che số, driver array / log, gọi ZNS qua HTTP giả lập,
 * làm mới token khi Zalo báo hết hạn, làm sạch lỗi (không lộ token). Chạy:  php tests/zalo_client_test.php
 */
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Config;
use App\Services\ZaloClient;
use App\Services\ZaloTransportException;

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $name . "\n";
};
$override = static function (array $values): void {
    // config() đọc file config/*.php qua App\Core\Config - ghi đè từng khoá cho test
    foreach ($values as $k => $v) {
        Config::set('zalo.' . $k, $v);
    }
};

echo "== Chuẩn hoá số điện thoại\n";
$cases = ['0912345678' => '84912345678', '+84 912 345 678' => '84912345678', '84912345678' => '84912345678', '0912.345.678' => '84912345678',
    '0035' => null, '' => null, '0212345678' => null, '091234567' => null, '09123456789' => null, 'abc' => null, '0812345678' => '84812345678'];
foreach ($cases as $in => $want) {
    $check("normalizePhone('$in')", ZaloClient::normalizePhone((string) $in) === $want);
}
$check('maskPhone che giữa', ZaloClient::maskPhone('84912345678') === '09•••••678');
$check('maskPhone rỗng', ZaloClient::maskPhone('') === '—');

$tokenFile = sys_get_temp_dir() . '/zalo_token_test_' . getmypid() . '.json';
@unlink($tokenFile);
$override(['token_file' => $tokenFile, 'app_id' => 'APP1', 'app_secret' => 'SECRET-XYZ', 'access_token' => 'AT-OLD', 'refresh_token' => 'RT-OLD', 'mode' => '']);

echo "== Driver array\n";
$override(['driver' => 'array']);
ZaloClient::$sent = [];
$c = new ZaloClient();
$c->send('84912345678', 'TPL1', ['customer_name' => 'An'], 'trk-1');
$check('array: ghi nhận 1 tin', count(ZaloClient::$sent) === 1 && ZaloClient::$sent[0]['phone'] === '84912345678');
ZaloClient::fail('Lỗi giả lập SECRET-XYZ');
try {
    $c->send('84912345678', 'TPL1', [], 't');
    $check('array: giả lập lỗi', false);
} catch (ZaloTransportException $e) {
    $check('array: giả lập lỗi + làm sạch secret', !str_contains($e->getMessage(), 'SECRET-XYZ'));
}
try {
    $c->send('0912345678', 'TPL1', [], 't');
    $check('từ chối SĐT chưa chuẩn hoá', false);
} catch (ZaloTransportException $e) {
    $check('từ chối SĐT chưa chuẩn hoá', true);
}
try {
    $c->send('84912345678', '', [], 't');
    $check('từ chối thiếu template', false);
} catch (ZaloTransportException $e) {
    $check('từ chối thiếu template', true);
}

echo "== Driver log (không gửi)\n";
$override(['driver' => 'log']);
@unlink(BASE_PATH . '/storage/logs/zalo.log');
$c->send('84912345678', 'TPL1', [], 't');
$log = (string) @file_get_contents(BASE_PATH . '/storage/logs/zalo.log');
$check('log: có dòng, số đã che', str_contains($log, 'KHÔNG gửi') && !str_contains($log, '84912345678') && !str_contains($log, '0912345678'));
@unlink(BASE_PATH . '/storage/logs/zalo.log');

echo "== Driver zns (HTTP giả lập)\n";
$override(['driver' => 'zns']);
$calls = [];
ZaloClient::$http = static function (string $m, string $url, array $h, string $body) use (&$calls): array {
    $calls[] = compact('m', 'url', 'h', 'body');
    return [200, json_encode(['error' => 0, 'message' => 'Success', 'data' => ['msg_id' => 'MSG123']])];
};
$id = $c->send('84912345678', 'TPL1', ['customer_name' => 'An', 'total_due' => '1.000đ'], 'trk-ok');
$sentBody = json_decode($calls[0]['body'], true);
$check('zns: trả msg_id', $id === 'MSG123');
$check('zns: gọi đúng URL + header access_token', $calls[0]['url'] === 'https://business.openapi.zalo.me/message/template' && in_array('access_token: AT-OLD', $calls[0]['h'], true));
$check('zns: body đủ trường', $sentBody['phone'] === '84912345678' && $sentBody['template_id'] === 'TPL1' && $sentBody['template_data']['total_due'] === '1.000đ' && $sentBody['tracking_id'] === 'trk-ok' && !isset($sentBody['mode']));
$override(['mode' => 'development']);
$calls = [];
$c->send('84912345678', 'TPL1', [], 'x');
$check('zns: mode development', (json_decode($calls[0]['body'], true)['mode'] ?? '') === 'development');
$override(['mode' => '']);

echo "== Token hết hạn -> làm mới rồi gửi lại\n";
$calls = [];
ZaloClient::$http = static function (string $m, string $url, array $h, string $body) use (&$calls): array {
    $calls[] = compact('url', 'h', 'body');
    if (str_contains($url, 'oauth')) {
        return [200, json_encode(['access_token' => 'AT-NEW', 'refresh_token' => 'RT-NEW', 'expires_in' => '3600'])];
    }
    return in_array('access_token: AT-OLD', $h, true)
        ? [200, json_encode(['error' => -124, 'message' => 'Access token is invalid'])]
        : [200, json_encode(['error' => 0, 'message' => 'Success', 'data' => ['msg_id' => 'MSG-NEW']])];
};
$id = $c->send('84912345678', 'TPL1', [], 'trk');
$check('refresh: gửi lại thành công', $id === 'MSG-NEW' && count($calls) === 3);
$oauth = $calls[1];
$check('refresh: gọi oauth với secret_key + refresh_token', str_contains($oauth['url'], 'oauth') && in_array('secret_key: SECRET-XYZ', $oauth['h'], true) && str_contains($oauth['body'], 'refresh_token=RT-OLD') && str_contains($oauth['body'], 'app_id=APP1'));
$saved = json_decode((string) file_get_contents($tokenFile), true);
$check('refresh: lưu cặp token mới', ($saved['access_token'] ?? '') === 'AT-NEW' && ($saved['refresh_token'] ?? '') === 'RT-NEW');
$calls = [];
$c->send('84912345678', 'TPL1', [], 'trk2');
$check('lần sau dùng token đã lưu (không refresh)', count($calls) === 1 && in_array('access_token: AT-NEW', $calls[0]['h'], true));

echo "== Lỗi nghiệp vụ Zalo\n";
ZaloClient::$http = static fn (string $m, string $u, array $h, string $b): array => [200, json_encode(['error' => -118, 'message' => 'user not found'])];
try {
    $c->send('84912345678', 'TPL1', [], 't');
    $check('-118 ném lỗi', false);
} catch (ZaloTransportException $e) {
    $check('-118 diễn giải + kèm mã', str_contains($e->getMessage(), 'chưa đăng ký Zalo') && str_contains($e->getMessage(), '-118'));
}
ZaloClient::$http = static fn (string $m, string $u, array $h, string $b): array => [200, json_encode(['error' => -999, 'message' => 'Lỗi lạ AT-NEW'])];
try {
    $c->send('84912345678', 'TPL1', [], 't');
    $check('mã lạ ném lỗi', false);
} catch (ZaloTransportException $e) {
    $check('mã lạ giữ thông điệp Zalo, đã che token', str_contains($e->getMessage(), 'Lỗi lạ') && !str_contains($e->getMessage(), 'AT-NEW'));
}
ZaloClient::$http = static fn (string $m, string $u, array $h, string $b): array => [503, 'Service Unavailable'];
try {
    $c->send('84912345678', 'TPL1', [], 't');
    $check('HTTP 503', false);
} catch (ZaloTransportException $e) {
    $check('HTTP 503 -> lỗi tạm thời', str_contains($e->getMessage(), '503'));
}
ZaloClient::$http = static fn (string $m, string $u, array $h, string $b): array => [200, 'khong phai json'];
try {
    $c->send('84912345678', 'TPL1', [], 't');
    $check('phản hồi lạ', false);
} catch (ZaloTransportException $e) {
    $check('phản hồi không phải JSON -> lỗi', true);
}

ZaloClient::$http = null;
@unlink($tokenFile);
echo "\n$pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
