<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Base cho mọi Controller.
 * Controller CHỈ xử lý HTTP (đọc input, gọi Service, render view/redirect),
 * KHÔNG chứa nghiệp vụ và KHÔNG chứa SQL.
 */
abstract class BaseController
{
    /**
     * Render view (dùng dấu chấm thay cho /, ví dụ "dashboard.index").
     * $layout = null nghĩa là render view không bọc layout (hiếm dùng).
     */
    protected function view(string $view, array $data = [], ?string $layout = 'layouts.main'): void
    {
        extract($data, EXTR_SKIP);

        $viewPath = $this->resolveViewPath($view);
        ob_start();
        require $viewPath;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;

            return;
        }

        $layoutPath = $this->resolveViewPath($layout);
        require $layoutPath; // $content và các biến đã extract ở trên vẫn khả dụng trong layout
    }

    private function resolveViewPath(string $view): string
    {
        $relative = str_replace('.', '/', $view) . '.php';
        $path = BASE_PATH . '/resources/views/' . $relative;

        if (!is_file($path)) {
            throw new RuntimeException("View không tồn tại: {$view} ({$path})");
        }

        return $path;
    }

    protected function redirect(string $path): void
    {
        redirect($path);
    }

    protected function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Đọc input VÔ HƯỚNG từ POST trước, fallback GET. Sai kiểu (mảng) -> $default,
     * không bao giờ trả mảng (STEP 8 phần A - xem App\Core\Request).
     */
    protected function input(string $key, string $default = ''): string
    {
        return Request::anyString($key, $default);
    }

    /** Chỉ lấy các khoá cho phép từ POST (không tin field lạ từ request), ép về chuỗi đã trim. */
    protected function only(array $keys): array
    {
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = Request::postStringOrNull($key); // thiếu / sai kiểu -> null
        }

        return $data;
    }

    /** Input DANH SÁCH dòng (vd tiers[i][field]) - khác input vô hướng, xem Request::rows(). */
    protected function postRows(string $name, array $fields): array
    {
        return Request::rows($name, $fields);
    }

    /** Bộ lọc danh sách từ query string: chỉ khoá cho phép, giá trị chuỗi đã trim. */
    protected function filters(array $keys): array
    {
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = Request::queryString($key); // thiếu / sai kiểu -> ''
        }

        return $data;
    }

    protected function currentPage(): int
    {
        $page = Request::queryString('page', '1');

        return ctype_digit($page) ? max(1, (int) $page) : 1;
    }

    /**
     * Id từ route param {id}: chỉ chấp nhận số nguyên dương, ngược lại 404.
     * (Không tin ID từ request: tồn tại/quyền vẫn do Service kiểm tra.)
     */
    protected function routeId(string $id): int
    {
        if (!ctype_digit($id) || (int) $id < 1) {
            $this->notFound();
        }

        return (int) $id;
    }

    protected function notFound(): void
    {
        http_response_code(404);
        require BASE_PATH . '/resources/views/errors/404.php';
        exit;
    }

    /** Lưu lỗi + input cũ cho form và quay lại $path (Post/Redirect/Get). */
    protected function backWithErrors(string $path, ValidationException $e, array $oldInput): void
    {
        Flash::setFormState($e->errors(), $oldInput);
        if (isset($e->errors()['_'])) {
            Flash::error($e->errors()['_'][0]);
        } else {
            Flash::error('Dữ liệu chưa hợp lệ, vui lòng kiểm tra các trường được đánh dấu.');
        }
        $this->redirect($path);
    }
}
