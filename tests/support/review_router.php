<?php

declare(strict_types=1);

/**
 * STEP 14 - router cho `php -S` để chạy ứng dụng trên một DATABASE TẠM (test cài mới / nâng cấp) mà không sửa .env.
 *   REVIEW_DB=<db_tạm> REVIEW_URL=http://127.0.0.1:8098 php -S 127.0.0.1:8098 tests/support/review_router.php
 */

$db = (string) getenv('REVIEW_DB');
if ($db === '' || $db === 'energy_dss') {
    http_response_code(500);
    exit('REVIEW_DB phải là DB tạm.');
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Trang điều khiển test trình duyệt (cùng origin với app) - CHỈ có trên server test php -S, không có trên Apache
if (in_array($path, ['/__live_harness.html', '/__login_harness.html', '/__bill_email_harness.html', '/__sidebar_harness.html'], true)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/' . ltrim(substr($path, 2), '_'));
    return true;
}
$static = dirname(__DIR__, 2) . '/public' . $path;
if ($path !== '/' && is_file($static)) {
    return false;
}

// Mock Bank trên server test: CHỈ theo biến môi trường của tiến trình (run_payment_tests.sh bật/tắt tường minh),
// bỏ qua MOCK_BANK_* trong .env local (demo) - đọc trước bootstrap vì Env::load putenv giá trị .env khi tiến trình không đặt.
$mockEnabled = (string) getenv('MOCK_BANK_ENABLED');
$mockSecret = (string) getenv('MOCK_BANK_SECRET');

require dirname(__DIR__, 2) . '/bootstrap.php';
App\Core\Config::set('payment.mock_bank.enabled', in_array(strtolower($mockEnabled), ['1', 'true', 'yes', 'on'], true));
App\Core\Config::set('payment.mock_bank.secret', $mockSecret);
$_ENV['DB_DATABASE'] = $db;
$_ENV['APP_URL'] = (string) getenv('REVIEW_URL');
App\Core\Config::set('app.url', (string) getenv('REVIEW_URL'));
App\Core\Config::set('database.database', $db);
// Test trên DB tạm KHÔNG BAO GIỜ gửi email thật: chỉ cho log / array (mặc định log), bỏ qua MAIL_DRIVER=smtp trong .env
$mailDriver = (string) getenv('REVIEW_MAIL_DRIVER');
App\Core\Config::set('mail.driver', in_array($mailDriver, ['log', 'array'], true) ? $mailDriver : 'log');

App\Core\Session::start();
$router = new App\Core\Router();
require BASE_PATH . '/routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ?? '/');
