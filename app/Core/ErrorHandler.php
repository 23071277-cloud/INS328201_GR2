<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Bắt toàn bộ lỗi PHP (warning/notice), exception chưa bắt, và fatal error,
 * ghi log ra storage/logs/app.log, không hiện lỗi PHP thô ra trình duyệt.
 */
class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        self::logAndRender("PHP Error [{$severity}]: {$message} in {$file}:{$line}");

        return true;
    }

    public static function handleException(Throwable $e): void
    {
        self::logAndRender(
            'Uncaught Exception: ' . $e->getMessage() .
            ' in ' . $e->getFile() . ':' . $e->getLine() .
            "\n" . $e->getTraceAsString()
        );
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::logAndRender("Fatal Error: {$error['message']} in {$error['file']}:{$error['line']}");
        }
    }

    private static function logAndRender(string $message): void
    {
        self::writeLog($message);

        if (!headers_sent()) {
            http_response_code(500);
        }

        $debug = false;
        try {
            $debug = (bool) Config::get('app.debug', false);
        } catch (Throwable $ignored) {
            // Config có thể chưa sẵn sàng nếu lỗi xảy ra quá sớm trong bootstrap.
        }

        $errorView = BASE_PATH . '/resources/views/errors/500.php';
        if (is_file($errorView)) {
            require $errorView;
        } else {
            echo 'Đã có lỗi xảy ra. Vui lòng thử lại sau.';
        }

        if ($debug) {
            echo '<pre style="background:#111;color:#f87171;padding:16px;white-space:pre-wrap;">'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
                . '</pre>';
        }

        exit;
    }

    /** Ghi một sự kiện (không phải lỗi PHP) vào app.log, vd cảnh báo bảo mật. Không render gì. */
    public static function logEvent(string $message): void
    {
        self::writeLog($message);
    }

    private static function writeLog(string $message): void
    {
        $logFile = BASE_PATH . '/storage/logs/app.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
