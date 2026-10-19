# Bảo mật — Energy & Utility DSS (Topic 09)

Mọi kiểm soát dưới đây đều được kiểm bằng test tự động: `tests/core_security_test.php` (145 route × 3 vai trò + chưa đăng nhập), `tests/fn01_*`, `tests/input_robustness_test.php` và các test FN-02 → FN-09.

## 1. Kiểm soát đã có

| Hạng mục | Cách làm | Nơi trong code |
|---|---|---|
| Mật khẩu | `password_hash` (bcrypt), `password_verify`; chính sách NFR-01: ≥ 8 ký tự, có chữ hoa và chữ số, ≤ 72 byte. Không lưu, ghi log, hiển thị lại hay đưa vào audit; form lỗi không điền lại ô mật khẩu; tham số mật khẩu có `#[\SensitiveParameter]` để stack trace không chứa mật khẩu | `AuthService`, `UserService` |
| Đăng nhập | Sai N lần → khoá tạm M phút (BR-02; N, M là tham số hệ thống). Thông báo không tiết lộ username có tồn tại hay không | `AuthService` |
| Phiên | Cookie `HttpOnly`, `SameSite=Lax`, `Secure` khi chạy HTTPS; đổi session ID khi đăng nhập (chống fixation); hết hạn khi không hoạt động (mặc định 30 phút) | `Session` |
| Thu hồi phiên | Mỗi request đọc lại tài khoản. Tài khoản bị ngừng / khoá tạm / bị đặt lại mật khẩu / bị xoá → huỷ phiên ở request kế tiếp, ghi audit `session_revoked`. Đổi vai trò có hiệu lực ngay | `AuthMiddleware` |
| CSRF | Mọi route POST có middleware `csrf` (token theo phiên, `hash_equals`); sai → 403 và ghi `[SECURITY]` vào log | `CsrfMiddleware` |
| RBAC | Quyền khai báo trên **route** (`rbac:<quyền>`), ma trận ở `config/permissions.php`. Truy cập URL trực tiếp khi không có quyền → 403 + audit `access_denied`. Controller / Service không tự kiểm tra vai trò. Menu ẩn mục không có quyền (chỉ là tiện ích, không phải lớp bảo vệ) | `RbacMiddleware` |
| SQL injection | Chỉ dùng PDO prepared statement (`EMULATE_PREPARES = false`); tên cột / thứ tự sắp xếp viết cứng trong Repository | `app/Repositories` |
| XSS | Mọi giá trị hiển thị qua `e()` (`htmlspecialchars`) | `resources/views` |
| Input sai kiểu | Mọi input đọc qua `App\Core\Request`; tham số dạng mảng (`x[]`) khi cần chuỗi → coi như rỗng, không lỗi 500 | `Request` |
| Che PII | SĐT cư dân chỉ BQL xem đầy đủ (`residents.view_phone`), vai trò khác thấy bản che; audit che khoá nhạy cảm (`password`, `token` trừ `version_token`, `secret`, `session`, `csrf`, `api_key`) thành `***` | `mask_phone()`, `AuditLogService` |
| Upload Excel | Chỉ `.xlsx`; kiểm MIME bằng `finfo`; ≤ 5 MB, ≤ 2.000 dòng; giới hạn số entry và dung lượng giải nén (chống zip bomb); XML đọc không mở rộng entity, không tải mạng (chống XXE); tên file gốc chỉ để hiển thị; file lưu trong `storage/imports` (ngoài web root) với tên `batch_<id>.xlsx`, xoá khi huỷ hoặc xác nhận | `ReadingImportService`, `XlsxReader` |
| CSV injection | Ô văn bản bắt đầu bằng `= + - @ tab CR` được thêm `'`; tải về có `X-Content-Type-Options: nosniff` | `CsvWriter`, `ReportController` |
| Audit | Ghi ai / khi nào / đối tượng / IP / giá trị cũ → mới trong **cùng transaction** với thay đổi (audit lỗi thì huỷ thao tác). Ứng dụng không có SQL hay route sửa / xoá log (BR-04); màn hình tra cứu chỉ đọc | `AuditLogger`, `BaseService` |
| Tham số hệ thống | Chỉ 5 khoá whitelist sửa được trên UI; `.env` (DB, `APP_DEBUG`, `APP_KEY`, `APP_URL`, đường dẫn) không có trên UI | `config/system_parameters.php` |
| Lỗi | `APP_DEBUG=false` → trang lỗi chung, không lộ SQL / stack trace; chi tiết ghi `storage/logs/app.log` | `ErrorHandler` |
| File nhạy cảm | `.htaccess` chặn `.env`, `.sql`, `.log`, `.md`; ứng dụng phục vụ từ `public/`; `Options -Indexes` tắt liệt kê thư mục | `public/.htaccess`, `.htaccess` |
| Email (FN-10.03) | Thông tin đăng nhập SMTP (Gmail **App Password**) chỉ ở `.env` (bị `.htaccess` chặn, có trong `.gitignore`); không lưu DB, không hiện UI, không ghi log / audit; lỗi gửi được làm sạch (bỏ mật khẩu / username / xuống dòng, ≤ 500 ký tự), không lưu transcript SMTP (`SMTPDebug = 0`), tạm bật `zend.exception_ignore_args` khi gửi để stack trace không chứa tham số; chống header injection (CR/LF ở người nhận / tên / tiêu đề bị từ chối, email `FILTER_VALIDATE_EMAIL`); nội dung HTML escape mọi giá trị, không chứa SĐT; email là dữ liệu cá nhân: vai trò không phải BQL thấy dạng che; xem trước nội dung trong `iframe sandbox`; driver `log` mặc định — không gửi ra Internet nếu chưa cấu hình | `EmailService`, `EmailNotificationService`, `config/mail.php` |
| Đồng thời | Version token + `SELECT … FOR UPDATE` + UNIQUE → không ghi đè im lặng, không trùng dữ liệu | `BaseService`, Repository |

## 2. Không có / ngoài phạm vi

- Không có MFA, không gửi email đặt lại mật khẩu (BQL đặt lại thủ công).
- Không giới hạn tần suất đăng nhập theo IP; chỉ khoá theo tài khoản.
- Không cấu hình HTTPS trong ứng dụng; đó là việc của máy chủ.
- Không có Content-Security-Policy header.
- Phiên đang dùng bị huỷ khi tài khoản rơi vào khoá tạm do người khác nhập sai mật khẩu (V6, cần BA xác nhận).

## 3. Production checklist

| # | Việc | Bắt buộc |
|---|---|---|
| 1 | `.env`: `APP_ENV=production`, `APP_DEBUG=false` | Có |
| 2 | Chạy qua HTTPS (cookie phiên tự gắn `Secure`) | Có |
| 3 | Đổi mật khẩu hoặc vô hiệu hoá 3 tài khoản demo (`Password@123`); tạo tài khoản thật bằng màn hình Quản lý tài khoản | Có |
| 4 | User DB riêng (không dùng `root`), mật khẩu mạnh, chỉ có quyền SELECT / INSERT / UPDATE / DELETE trên `energy_dss_v2` (DB hiện hành; `energy_dss` chỉ giữ để rollback) | Có |
| 5 | DocumentRoot / VirtualHost trỏ vào `public/`; giữ `Options -Indexes`; `AllowOverride` cho phép `.htaccess` | Có |
| 6 | Quyền file: user Apache chỉ cần **ghi** `storage/logs` và `storage/imports`; mã nguồn và `.env` chỉ đọc. Không `chmod 777` | Có |
| 7 | Hệ thống thật: không dùng dữ liệu demo của `database/energy_dss_v2.sql` (3 tài khoản mật khẩu công khai) — đổi mật khẩu / vô hiệu hoá trước khi vận hành | Có |
| 8 | Cấu hình `bank_account_info` và kiểm tra các tham số tại `/system-parameters` | Có |
| 9 | Sao lưu định kỳ DB + `storage/logs`; xoay vòng log | Khuyến nghị |
| 10 | Giới hạn tần suất đăng nhập ở tầng web server / WAF | Khuyến nghị |
| 11 | BA chốt các điểm ảnh hưởng tiền (J-07, J-08, J-09, J-15, J-16, J-19) trước khi phát hành phiếu thật | Có — xem README mục 11 |
