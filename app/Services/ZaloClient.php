<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

/**
 * Transport Zalo ZNS. Chỉ lo việc GỬI + giữ token; không biết gì về nghiệp vụ (xem ZaloNotificationService).
 *   log   - demo offline: KHÔNG gửi; ghi 1 dòng tóm tắt (số đã che, mã mẫu) vào storage/logs/zalo.log
 *   array - test tự động: giữ tin trong bộ nhớ (ZaloClient::$sent); ZaloClient::fail() giả lập lỗi
 *   zns   - gọi API ZNS: POST {api_url} (header access_token) {phone, template_id, template_data, tracking_id[, mode]}
 * Token: access_token hết hạn sau ~1 giờ. Khi Zalo báo token sai / hết hạn (-124 / -216) hoặc chưa có token, client tự làm mới bằng
 * refresh_token (POST {oauth_url}, header secret_key) rồi lưu cặp token mới vào token_file (refresh_token dùng 1 lần).
 * Bảo mật: token / secret chỉ đọc từ config, không ghi log; mọi lỗi trả về dạng đã làm sạch (sanitize).
 */
class ZaloClient
{
    /** @var array<int, array{phone:string, template_id:string, data:array<string,string>, tracking_id:string}> tin đã "gửi" (driver array) */
    public static array $sent = [];
    private static ?string $failWith = null;
    /** Test: thay HTTP thật. callable(string $method, string $url, array $headers, string $body): array{0:int,1:string} [HTTP status, body] */
    public static $http = null;

    /** Mã lỗi Zalo nghĩa là token sai / hết hạn -> thử làm mới 1 lần. */
    private const TOKEN_ERRORS = [-124, -216, -14];

    public static function fail(?string $message): void
    {
        self::$failWith = $message;
    }

    public function driver(): string
    {
        $driver = (string) config('zalo.driver', 'log');

        return in_array($driver, (array) config('zalo.drivers', []), true) ? $driver : 'log';
    }

    /** Chuẩn hoá số di động VN về 84xxxxxxxxx; null nếu không hợp lệ. Nhận 0912..., +84912..., 84912..., có dấu cách / chấm / gạch. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $digits = '84' . substr($digits, 1);
        }

        return preg_match('/^84[35789]\d{8}$/', $digits) === 1 ? $digits : null;
    }

    /** "84912345678" -> "09•••••678" (che số trên giao diện cho người không có quyền xem SĐT đầy đủ). */
    public static function maskPhone(?string $phone84): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone84) ?? '';
        if (strlen($d) < 6) {
            return '—';
        }
        $local = str_starts_with($d, '84') ? '0' . substr($d, 2) : $d;

        return substr($local, 0, 2) . str_repeat('•', max(2, strlen($local) - 5)) . substr($local, -3);
    }

    /** @return string msg_id (rỗng với driver log / array) @throws ZaloTransportException */
    public function send(string $phone84, string $templateId, array $data, string $trackingId): string
    {
        if (self::normalizePhone($phone84) !== $phone84) {
            throw new ZaloTransportException('Số điện thoại không hợp lệ để gửi Zalo.');
        }
        if ($templateId === '') {
            throw new ZaloTransportException('Chưa cấu hình mẫu tin Zalo (ZALO_TEMPLATE_BILL trong .env).');
        }
        try {
            return match ($this->driver()) {
                'zns' => $this->sendZns($phone84, $templateId, $data, $trackingId),
                'array' => $this->sendArray($phone84, $templateId, $data, $trackingId),
                default => $this->sendLog($phone84, $templateId),
            };
        } catch (ZaloTransportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ZaloTransportException(self::sanitize($e->getMessage()));
        }
    }

    private function sendArray(string $phone, string $templateId, array $data, string $trackingId): string
    {
        if (self::$failWith !== null) {
            $m = self::$failWith;
            self::$failWith = null;
            throw new ZaloTransportException(self::sanitize($m));
        }
        self::$sent[] = ['phone' => $phone, 'template_id' => $templateId, 'data' => $data, 'tracking_id' => $trackingId];

        return '';
    }

    private function sendLog(string $phone, string $templateId): string
    {
        $line = sprintf("[%s] ZALO(log driver, KHÔNG gửi) to=%s template=%s\n", date('Y-m-d H:i:s'), self::maskPhone($phone), $templateId);
        @file_put_contents(BASE_PATH . '/storage/logs/zalo.log', $line, FILE_APPEND | LOCK_EX);

        return '';
    }

    private function sendZns(string $phone, string $templateId, array $data, string $trackingId): string
    {
        $token = $this->accessToken();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $body = ['phone' => $phone, 'template_id' => $templateId, 'template_data' => $data, 'tracking_id' => substr($trackingId, 0, 48)];
            if ((string) config('zalo.mode', '') === 'development') {
                $body['mode'] = 'development';
            }
            $res = $this->request('POST', (string) config('zalo.api_url'), ['access_token: ' . $token, 'Content-Type: application/json'],
                (string) json_encode($body, JSON_UNESCAPED_UNICODE));
            $json = json_decode($res, true);
            if (!is_array($json) || !array_key_exists('error', $json)) {
                throw new ZaloTransportException('Zalo trả về phản hồi không hợp lệ.');
            }
            $code = (int) $json['error'];
            if ($code === 0) {
                return substr((string) ($json['data']['msg_id'] ?? ''), 0, 80);
            }
            if ($attempt === 0 && in_array($code, self::TOKEN_ERRORS, true)) {
                $token = $this->refreshToken(); // token hết hạn -> làm mới 1 lần rồi gửi lại
                continue;
            }
            throw new ZaloTransportException(self::describeError($code, (string) ($json['message'] ?? '')));
        }
        throw new ZaloTransportException('Gửi Zalo không thành công.');
    }

    /** Diễn giải dễ hiểu cho một số mã lỗi; mã khác giữ nguyên thông điệp Zalo (đã làm sạch). */
    public static function describeError(int $code, string $message): string
    {
        // Chỉ diễn giải mã chắc chắn; mã khác giữ nguyên thông điệp Zalo (xem bảng mã lỗi trong docs/ZALO_ZNS.md).
        $known = [
            -118 => 'Số điện thoại chưa đăng ký Zalo hoặc không nhận được tin.',
            -124 => 'Token Zalo không hợp lệ hoặc đã hết hạn.',
            -216 => 'Token Zalo không hợp lệ hoặc đã hết hạn.',
        ];
        $text = $known[$code] ?? ('Zalo từ chối: ' . ($message !== '' ? $message : 'lỗi không rõ'));

        return self::sanitize($text . ' (mã ' . $code . ')');
    }

    private function accessToken(): string
    {
        $stored = $this->readTokenFile();
        $token = (string) ($stored['access_token'] ?? config('zalo.access_token', ''));
        if ($token === '' || (isset($stored['expires_at']) && (int) $stored['expires_at'] <= time() + 30)) {
            return $this->refreshToken();
        }

        return $token;
    }

    /** Làm mới token bằng refresh_token (file token ưu tiên hơn .env vì refresh_token chỉ dùng 1 lần). */
    private function refreshToken(): string
    {
        $stored = $this->readTokenFile();
        $refresh = (string) ($stored['refresh_token'] ?? config('zalo.refresh_token', ''));
        $appId = (string) config('zalo.app_id', '');
        $secret = (string) config('zalo.app_secret', '');
        if ($refresh === '' || $appId === '' || $secret === '') {
            throw new ZaloTransportException('Chưa cấu hình token Zalo (ZALO_APP_ID / ZALO_APP_SECRET / ZALO_REFRESH_TOKEN trong .env).');
        }
        $res = $this->request('POST', (string) config('zalo.oauth_url'), ['secret_key: ' . $secret, 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['app_id' => $appId, 'grant_type' => 'refresh_token', 'refresh_token' => $refresh]));
        $json = json_decode($res, true);
        $access = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
        $newRefresh = is_array($json) ? (string) ($json['refresh_token'] ?? '') : '';
        if ($access === '' || $newRefresh === '') {
            $msg = is_array($json) ? (string) ($json['error_description'] ?? $json['message'] ?? '') : '';
            throw new ZaloTransportException(self::sanitize('Không làm mới được token Zalo' . ($msg !== '' ? ': ' . $msg : '.') . ' Cần lấy lại token ở Zalo for Developers.'));
        }
        $this->writeTokenFile(['access_token' => $access, 'refresh_token' => $newRefresh, 'expires_at' => time() + max(60, (int) ($json['expires_in'] ?? 3600))]);

        return $access;
    }

    private function readTokenFile(): array
    {
        $file = (string) config('zalo.token_file', '');
        if ($file === '' || !is_file($file)) {
            return [];
        }
        $json = json_decode((string) @file_get_contents($file), true);

        return is_array($json) ? $json : [];
    }

    private function writeTokenFile(array $tokens): void
    {
        $file = (string) config('zalo.token_file', '');
        if ($file === '') {
            return;
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, json_encode($tokens), LOCK_EX) === false) {
            throw new ZaloTransportException('Không ghi được file token Zalo (storage/zalo_token.json) - kiểm tra quyền thư mục storage.');
        }
        @chmod($tmp, 0600);
        @rename($tmp, $file);
    }

    /** @return string body @throws ZaloTransportException khi lỗi mạng / HTTP */
    private function request(string $method, string $url, array $headers, string $body): string
    {
        if (self::$http !== null) {
            [$status, $out] = (self::$http)($method, $url, $headers, $body);
        } else {
            if (!function_exists('curl_init')) {
                throw new ZaloTransportException('Máy chủ chưa bật extension curl của PHP.');
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => max(5, (int) config('zalo.timeout', 15)),
                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false,
            ]);
            $out = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($out === false) {
                throw new ZaloTransportException(self::sanitize('Không kết nối được Zalo: ' . $err));
            }
        }
        // Zalo trả lỗi nghiệp vụ trong JSON (HTTP 200); HTTP lỗi mà body không phải JSON mới coi là lỗi mạng
        if ($status >= 500 || ($status >= 400 && !is_array(json_decode((string) $out, true)))) {
            throw new ZaloTransportException('Zalo tạm thời không phản hồi (HTTP ' . $status . ').');
        }

        return (string) $out;
    }

    /** Bỏ token / secret / xuống dòng / chi tiết dài khỏi thông báo trước khi lưu hoặc hiển thị. */
    public static function sanitize(string $message): string
    {
        $secrets = [(string) config('zalo.app_secret', ''), (string) config('zalo.access_token', ''), (string) config('zalo.refresh_token', '')];
        $file = (string) config('zalo.token_file', '');
        if ($file !== '' && is_file($file)) {
            $j = json_decode((string) @file_get_contents($file), true);
            $secrets[] = (string) ($j['access_token'] ?? '');
            $secrets[] = (string) ($j['refresh_token'] ?? '');
        }
        foreach (array_filter($secrets, static fn ($s) => $s !== '') as $s) {
            $message = str_ireplace($s, '***', $message);
        }
        $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?? '';

        return mb_substr(trim($message) === '' ? 'Gửi Zalo không thành công.' : trim($message), 0, 500);
    }
}
