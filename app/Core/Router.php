<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\MiddlewareInterface;
use App\Middleware\RbacMiddleware;

/**
 * Router tối giản cho PHP thuần: hỗ trợ GET/POST, route param {id}, middleware
 * theo alias chuỗi (vd: ['auth', 'rbac:users.view', 'csrf']).
 */
class Router
{
    /** @var array<int, array{method:string,uri:string,action:array,middleware:array}> */
    private array $routes = [];

    /** @var array<string,class-string<MiddlewareInterface>> */
    private array $middlewareMap = [
        'auth' => AuthMiddleware::class,
        'rbac' => RbacMiddleware::class,
        'csrf' => CsrfMiddleware::class,
    ];

    public function get(string $uri, array $action, array $middleware = []): void
    {
        $this->addRoute('GET', $uri, $action, $middleware);
    }

    public function post(string $uri, array $action, array $middleware = []): void
    {
        $this->addRoute('POST', $uri, $action, $middleware);
    }

    private function addRoute(string $method, string $uri, array $action, array $middleware): void
    {
        $this->routes[] = [
            'method' => $method,
            'uri' => '/' . trim($uri, '/'),
            'action' => $action,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(string $method, string $requestUri): void
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($this->stripBasePath($path), '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = $this->compilePattern($route['uri']);
            if (!preg_match($pattern, $path, $matches)) {
                continue;
            }

            if (!$this->runMiddleware($route['middleware'])) {
                return;
            }

            $params = array_filter(
                $matches,
                static fn ($key) => is_string($key),
                ARRAY_FILTER_USE_KEY
            );

            [$controllerClass, $methodName] = $route['action'];
            $controller = new $controllerClass();
            call_user_func_array([$controller, $methodName], $params);

            return;
        }

        http_response_code(404);
        require BASE_PATH . '/resources/views/errors/404.php';
    }

    private function runMiddleware(array $middlewareList): bool
    {
        foreach ($middlewareList as $middlewareEntry) {
            [$name, $param] = array_pad(explode(':', $middlewareEntry, 2), 2, null);
            $middlewareClass = $this->middlewareMap[$name] ?? null;

            if ($middlewareClass === null) {
                continue;
            }

            /** @var MiddlewareInterface $instance */
            $instance = new $middlewareClass();
            if (!$instance->handle($param)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bỏ tiền tố thư mục cài đặt khỏi path trước khi so route. Khi chạy qua
     * Apache/XAMPP, REQUEST_URI là "/energy-dss/public/login" nhưng route khai
     * báo "/login". Tiền tố lấy từ APP_URL (vd "/energy-dss/public") và bản bỏ
     * "/public" (vd "/energy-dss", khi vào qua .htaccess ở thư mục gốc).
     * Khi chạy `php -S` tại "/", không tiền tố nào khớp nên path giữ nguyên.
     */
    private function stripBasePath(string $path): string
    {
        $basePath = rtrim((string) parse_url((string) config('app.url', ''), PHP_URL_PATH), '/');
        $candidates = [$basePath, (string) preg_replace('#/public$#', '', $basePath)];

        foreach ($candidates as $prefix) {
            if ($prefix === '') {
                continue;
            }
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return substr($path, strlen($prefix)) ?: '/';
            }
        }

        return $path;
    }

    private function compilePattern(string $uri): string
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $uri);

        return '#^' . $pattern . '$#u';
    }
}
