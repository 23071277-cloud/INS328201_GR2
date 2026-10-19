<?php

declare(strict_types=1);

/**
 * Bootstrap: autoload PSR-4 thủ công (không Composer), nạp helpers, .env,
 * timezone, và đăng ký Error Handler. public/index.php require file này đầu tiên.
 */

define('BASE_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// STEP 25 (FN-10.03): PHPMailer 7.1.1 vendored trong lib/PHPMailer (LGPL-2.1, không Composer) - chỉ dùng làm SMTP transport.
spl_autoload_register(static function (string $class): void {
    $prefix = 'PHPMailer\\PHPMailer\\';
    if (str_starts_with($class, $prefix)) {
        $file = BASE_PATH . '/lib/PHPMailer/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require BASE_PATH . '/app/Helpers/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set((string) App\Core\Env::get('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));

// Đảm bảo PHP xử lý chuỗi UTF-8 đúng (tránh lỗi dấu hỏi ??? khi mbstring dùng charset khác)
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

App\Core\ErrorHandler::register();
