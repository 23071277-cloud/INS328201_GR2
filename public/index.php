<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Router;
use App\Core\Session;

// Chỉ áp dụng khi chạy bằng `php -S` (vd: php -S 127.0.0.1:8099 index.php) để
// built-in server tự trả file tĩnh (css/js) như Apache .htaccess đã làm trên
// XAMPP/MAMP thật. KHÔNG ảnh hưởng gì khi chạy qua Apache (SAPI khác 'cli-server').
if (PHP_SAPI === 'cli-server') {
    $requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $staticFile = __DIR__ . $requestedPath;
    if ($requestedPath !== '/' && is_file($staticFile)) {
        return false;
    }
}

Session::start();

$router = new Router();
require BASE_PATH . '/routes/web.php';

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ?? '/');
