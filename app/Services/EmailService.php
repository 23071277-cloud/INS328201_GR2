<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * STEP 25 - FN-10.03 transport email. Chỉ lo việc GỬI; không biết gì về nghiệp vụ (xem EmailNotificationService).
 * Driver theo .env MAIL_DRIVER:
 *   smtp  - gửi thật qua SMTP AUTH (vd Gmail: smtp.gmail.com:587 STARTTLS + App Password) bằng PHPMailer vendored
 *   log   - demo offline: KHÔNG gửi ra Internet; ghi 1 dòng tóm tắt (không nội dung) vào storage/logs/mail.log
 *   array - test tự động: giữ thư trong bộ nhớ (EmailService::$sent); có thể giả lập lỗi bằng EmailService::fail()
 * Bảo mật: thông tin đăng nhập chỉ đọc từ config (.env), không đi qua tham số hàm, không ghi log; mọi lỗi được bắt tại đây
 * và trả về thông báo đã làm sạch (EmailTransportException) - không để stack trace (có thể chứa tham số SMTP) lọt ra log.
 */
class EmailService
{
    /** @var array<int, array{to:string, name:string, subject:string, html:string, text:string, inline:array<string,string>}> thư đã "gửi" (driver array) */
    public static array $sent = [];

    private static ?string $failWith = null;

    /** Test: lần gửi tiếp theo (driver array) ném lỗi với thông báo này; null = gửi thành công. */
    public static function fail(?string $message): void
    {
        self::$failWith = $message;
    }

    public function driver(): string
    {
        $driver = (string) config('mail.driver', 'log');

        return in_array($driver, config('mail.drivers', []), true) ? $driver : 'log';
    }

    /**
     * @param array<string,string> $inline ảnh nhúng trong HTML (Content-ID => đường dẫn file), HTML tham chiếu bằng src="cid:<id>"
     *        (Gmail chặn ảnh data: và không tải được ảnh từ localhost). Bỏ trống = như trước.
     * @throws EmailTransportException thông báo đã làm sạch (không chứa thông tin đăng nhập)
     */
    public function send(string $to, string $toName, string $subject, string $html, string $text, array $inline = []): void
    {
        // Chống header injection: không chấp nhận CR/LF ở các trường đi vào header; địa chỉ phải hợp lệ
        if (preg_match('/[\r\n]/', $to . $toName . $subject) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new EmailTransportException('Địa chỉ hoặc tiêu đề email không hợp lệ.');
        }

        $driver = $this->driver();
        try {
            match ($driver) {
                'smtp' => $this->sendSmtp($to, $toName, $subject, $html, $text, $inline),
                'array' => $this->sendArray($to, $toName, $subject, $html, $text, $inline),
                default => $this->sendLog($to, $subject),
            };
        } catch (EmailTransportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new EmailTransportException(self::sanitize($e->getMessage()));
        }
    }

    private function sendSmtp(string $to, string $toName, string $subject, string $html, string $text, array $inline = []): void
    {
        $cfg = config('mail');
        if ($cfg['username'] === '' || $cfg['password'] === '' || $cfg['host'] === '') {
            throw new EmailTransportException('Chưa cấu hình SMTP (MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD trong .env).');
        }
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '1'); // stack trace (nếu có) không chứa tham số, kể cả mật khẩu
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->SMTPDebug = 0; // không ghi transcript SMTP (có lệnh AUTH)
            $mail->Host = $cfg['host'];
            $mail->Port = $cfg['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['username'];
            $mail->Password = $cfg['password'];
            $mail->SMTPSecure = $cfg['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
            $mail->Timeout = max(5, (int) $cfg['timeout']);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->setFrom($cfg['from_address'] !== '' ? $cfg['from_address'] : $cfg['username'], $cfg['from_name']);
            $mail->addAddress($to, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            foreach ($inline as $cid => $path) {
                if (!is_file($path) || !$mail->addEmbeddedImage($path, (string) $cid, basename($path))) {
                    throw new EmailTransportException('Không đính kèm được ảnh trong email.');
                }
            }
            $mail->send();
        } catch (Throwable $e) {
            throw new EmailTransportException(self::sanitize($e->getMessage()));
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '0' : (string) $previous);
        }
    }

    private function sendArray(string $to, string $toName, string $subject, string $html, string $text, array $inline = []): void
    {
        if (self::$failWith !== null) {
            $message = self::$failWith;
            self::$failWith = null;
            throw new EmailTransportException(self::sanitize($message));
        }
        self::$sent[] = ['to' => $to, 'name' => $toName, 'subject' => $subject, 'html' => $html, 'text' => $text, 'inline' => $inline];
    }

    /** Demo offline: không gửi; chỉ ghi tóm tắt (địa chỉ đã che, tiêu đề) - nội dung đầy đủ đã lưu ở email_notifications. */
    private function sendLog(string $to, string $subject): void
    {
        $line = sprintf("[%s] MAIL(log driver, KHÔNG gửi) to=%s subject=%s\n", date('Y-m-d H:i:s'), mask_email($to), $subject);
        @file_put_contents(BASE_PATH . '/storage/logs/mail.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** Bỏ thông tin đăng nhập / xuống dòng / chi tiết dài khỏi thông báo lỗi trước khi lưu hoặc hiển thị. */
    public static function sanitize(string $message): string
    {
        $secrets = array_filter([(string) config('mail.password', ''), (string) config('mail.username', '')], static fn ($s) => $s !== '');
        foreach ($secrets as $secret) {
            $message = str_ireplace($secret, '***', $message);
        }
        $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?? '';
        $message = preg_replace('/\b(AUTH|PASS(WORD)?)\b\s*\S+/i', '$1 ***', $message) ?? '';

        return mb_substr(trim($message) === '' ? 'Gửi email không thành công.' : trim($message), 0, 500);
    }
}
