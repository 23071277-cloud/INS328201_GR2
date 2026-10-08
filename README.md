# Energy & Utility DSS — Topic 09 (Nhóm 2)

Hệ thống hỗ trợ ra quyết định (Decision Support System) cho ban quản lý chung cư: ghi chỉ số điện / nước, tính phí theo biểu giá bậc thang, so sánh tiêu thụ, phát hiện bất thường và hỗ trợ con người xử lý cảnh báo.
PHP 8.2 thuần + MariaDB, chạy trên XAMPP, **không cần Internet**.

| | |
|---|---|
| Phạm vi | Core **FN-01 → FN-09** (53 chức năng) + **FN-10.03** gửi phiếu thông báo phí qua email (Secondary) |
| Trạng thái Core | 41 PASS / 11 PARTIAL / 1 BLOCKED / 0 MISSING (bảng độ phủ chi tiết: lưu trữ, mục 12) |
| CSDL hiện hành | **`energy_dss_v2`** — schema **27 bảng** = 26 bảng 3NF + `payment_transactions` (Payment module; DB thật hiện 26 bảng, bảng thanh toán thêm khi migrate có duyệt). `energy_dss` (schema cũ 29 bảng) giữ nguyên để **rollback** |
| Kiểm thử | `bash tests/run_all_tests.sh` — lần chạy gần nhất 04/10/2026 (mọi DB tạm dựng từ `database/energy_dss_v2.sql`): **2.171/2.171 kiểm tra PASS**, lint 294 file 0 lỗi, exit 0 |

---

## 1. Tổng quan

```
Chỉ số công tơ → Duyệt → Tính phí → So sánh (kỳ trước / baseline) → Mục tiêu → Ngưỡng → Cảnh báo
→ Con người xem xét (review, kiểm tra hiện trường, kết luận) → Khuyến nghị theo quy tắc → Dashboard / báo cáo / phiếu phí
```

Hệ thống **không tự chẩn đoán**: cảnh báo là tín hiệu kèm lý do, người dùng quyết định xử lý; khuyến nghị dựa trên quy tắc do BQL cấu hình (không AI / ML / API ngoài).

## 2. Vai trò

| Vai trò | Tài khoản demo | Công việc chính |
|---|---|---|
| **Ban quản lý (BQL)** | `bql01` | Danh mục, biểu giá (duyệt đề xuất), tạo kỳ, duyệt chỉ số, baseline / mục tiêu / ngưỡng, xử lý cảnh báo (gồm J-16), khuyến nghị, tài khoản, audit, tham số |
| **Kỹ thuật** | `kythuat01` | Nhập / import chỉ số, nhận yêu cầu và trả kết quả kiểm tra hiện trường |
| **Kế toán** | `ketoan01` | Đề xuất biểu giá, tính phí, xác nhận, khóa kỳ, báo cáo, phiếu thông báo phí, gửi email phiếu, xử lý giao dịch thanh toán cần kiểm tra |

Mật khẩu demo chung: `Password@123` (**chỉ dùng demo**, DB chỉ lưu hash bcrypt). Sai 5 lần liên tiếp → khoá tạm 30 phút (chỉnh được ở Tham số hệ thống).
Quyền kiểm tra ở **từng route** (gõ URL trực tiếp vẫn bị 403); ma trận đầy đủ: `config/permissions.php`.

## 3. Chức năng chính

| Module | Màn hình (URL sau `/public`) | Nội dung |
|---|---|---|
| FN-01 Tài khoản & bảo mật | `/login`, `/users`, `/audit-logs`, `/system-parameters` | Đăng nhập, RBAC 3 vai trò, khoá tài khoản, nhật ký audit, tham số hệ thống |
| FN-02 Danh mục | `/buildings`, `/zones`, `/apartments`, `/meters` | Tòa nhà → khu → căn hộ → cư dân / công tơ / xe; không xoá cứng, chỉ "Ngừng" |
| FN-03 Biểu giá & phí | `/tariffs`, `/fees`, `/tariff-proposals` | Phiên bản giá theo ngày hiệu lực, bậc thang, phí cố định; Kế toán đề xuất → BQL duyệt |
| FN-04 Chỉ số công tơ | `/periods`, `/periods/{id}/readings/create`, `/periods/{id}/imports/create`, `/periods/{id}/approvals` | Nhập tay / import Excel (xem trước, đối chiếu tổng), duyệt chỉ số |
| FN-05 Tính phí & khóa kỳ | `/periods/{id}/billing`, `/charges/{id}`, `/periods/{id}/billing/review` | Tính phí bằng bcmath theo xu, giải trình từng bậc, xác nhận bốn mắt, khóa / mở lại kỳ |
| FN-06 Phân tích | `/baselines`, `/comparisons`, `/targets`, `/targets/tracking` | Baseline, so sánh kỳ trước / baseline, mục tiêu tiêu thụ |
| FN-07 Cảnh báo | `/alert-rules`, `/alerts`, `/alerts/{id}` | Quy tắc ngưỡng, đánh giá, review → kiểm tra hiện trường → kết luận → đóng |
| J-16 *(BA Proposal)* | `/alerts?alert_type=no_usage_consumption` | Căn khai báo "không phát sinh" nhưng có tiêu thụ → cảnh báo hệ thống; chặn xác nhận bản tính tới khi BQL kết luận |
| FN-08 Khuyến nghị | `/recommendation-rules`, mục khuyến nghị trong `/alerts/{id}` | Khuyến nghị theo quy tắc, lưu snapshot lúc sinh |
| FN-09 Dashboard & báo cáo | `/dashboard`, `/reports/utility`, `/charges/{id}/notice`, `/periods/{id}/notices` | Tổng hợp theo vai trò, báo cáo + CSV, phiếu thông báo phí |
| FN-10.03 Email *(Secondary)* | `/periods/{id}/bill-emails`, `/email-notifications` | Gửi phiếu thủ công theo kỳ đã khóa, hộp thư đi, gửi lại khi lỗi |
| Thanh toán *(Payment module)* | `/payments`, `/payments/review` (Mock Bank là ứng dụng giả lập ĐỘC LẬP `tools/mock-bank/`, chạy riêng — không thuộc Energy DSS) | Trạng thái Chưa thanh toán / Đã thanh toán / Cần kiểm tra (tính từ giao dịch), đối soát tự động theo mã thanh toán + đúng số tiền, xử lý giao dịch cần kiểm tra, email xác nhận 1 lần, ngân hàng **giả lập** (local / demo) |

## 4. Công nghệ

| Thành phần | Lựa chọn |
|---|---|
| Ngôn ngữ / kiến trúc | PHP 8.2 thuần, MVC + Service + Repository; router, middleware (auth → RBAC → CSRF) tự viết; không framework, không Composer |
| CSDL | MariaDB 10.4 (XAMPP) / MySQL 8.0.16+, InnoDB, `utf8mb4`; PDO prepared statement thật (không emulate) |
| Tiền tệ | bcmath, tính theo xu (không float) |
| Excel / CSV | Đọc / ghi `.xlsx` bằng `ext-zip` + `ext-simplexml`; CSV chống formula injection |
| Giao diện | HTML + CSS + JS thuần, biểu đồ SVG vẽ phía server; không CDN |
| Email | PHPMailer 7.1.1 vendored (`lib/PHPMailer`, LGPL-2.1), chỉ làm SMTP transport |
| Kiểm thử | Script PHP CLI + bash; curl qua Apache thật và `php -S`; Chrome headless cho UI |

Kiến trúc tóm tắt: [`docs/ARCHITECTURE_SUMMARY.md`](docs/ARCHITECTURE_SUMMARY.md).

## 5. Cấu trúc thư mục

```
energy-dss/
├── app/                 Core/ (Router, Session, Env, Schema…), Middleware/, Controllers/ (31), Services/ (51), Repositories/ (28), Support/, Helpers/
├── config/              permissions, billing, alerts, j16, mail, pricing, readings, master_data, system_parameters…
├── routes/web.php       toàn bộ route + middleware
├── resources/views/     giao diện PHP theo module
├── public/              index.php (front controller), .htaccess, assets/
├── lib/PHPMailer/       thư viện SMTP (vendored)
├── database/
│   └── energy_dss_v2.sql   FILE CSDL DUY NHẤT: schema 27 bảng + dữ liệu demo (tổng hợp, không có dữ liệu thật)
├── docs/                tài liệu (mục 12) + demo/chi_so_07_2024_demo.xlsx
├── storage/             logs/, imports/, exports/ (runtime)
├── tests/               run_all_tests.sh, run_*.sh, *_test.php, support/
├── bootstrap.php, .env.example, .htaccess
```

Lịch sử CSDL (schema v1, seed v1, patch 001 → 005, script migrate v1 → v2, backup, fixture test nâng cấp) đã chuyển ra ngoài bản chạy cuối: `~/energy-dss-archive/database-history/` — xem [`docs/DATABASE.md`](docs/DATABASE.md) mục 6.

## 6. Cài đặt

**Yêu cầu**: XAMPP (Apache 2.4 + `mod_rewrite`, `AllowOverride All`), PHP **8.2+** với `pdo_mysql`, `mbstring`, `bcmath`, `zip`, `simplexml`, `fileinfo` (test cần thêm `curl`), MariaDB 10.2+ / MySQL 8.0.16+.

1. Đặt project vào `htdocs/` (copy hoặc symlink): phải có `htdocs/energy-dss/public/index.php`.
2. Khởi động Apache + MySQL trong XAMPP.
3. Tạo `.env`: `cp .env.example .env`. Mặc định XAMPP (`root`, mật khẩu trống, cổng 3306, `DB_DATABASE=energy_dss_v2`, `MAIL_DRIVER=log`) không cần sửa.
4. Tạo database (mục 7).
5. Cho Apache **ghi** `storage/logs` và **ghi + xoá** trong `storage/imports` (**không `chmod 777`**):
   - macOS + XAMPP (Apache chạy bằng `daemon`) — ACL chỉ trên 2 thư mục này:
     ```bash
     chmod +a "user:daemon allow list,search,add_file,readattr,readextattr,readsecurity" storage/logs
     chmod +a "user:daemon allow read,write,append,readattr,readextattr,readsecurity,file_inherit,only_inherit" storage/logs
     chmod +a "user:$(whoami) allow read,write,append,readattr,readextattr,readsecurity,file_inherit,only_inherit" storage/logs
     [ -f storage/logs/app.log ] && chmod +a "user:daemon allow read,write,append,readattr,readextattr,readsecurity" storage/logs/app.log
     chmod +a "user:daemon allow list,search,add_file,delete_child,readattr,readextattr,readsecurity" storage/imports
     chmod +a "user:daemon allow read,write,append,delete,readattr,readextattr,readsecurity,file_inherit,only_inherit" storage/imports
     chmod +a "user:$(whoami) allow read,write,append,delete,readattr,readextattr,readsecurity,file_inherit,only_inherit" storage/imports
     ```
   - Linux: `setfacl -m u:www-data:rwX storage/logs storage/imports`. Windows + XAMPP: thường không cần.
   - Kiểm tra: `curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost/energy-dss/public/login` trả `403` và `storage/logs/app.log` có thêm dòng `[SECURITY] CSRF token rejected`.

## 7. Cơ sở dữ liệu (`energy_dss_v2`)

Một file duy nhất: **`database/energy_dss_v2.sql`** (schema 27 bảng + dữ liệu demo). Cài mới, ở thư mục project (macOS: dùng `/Applications/XAMPP/bin/mysql` nếu `mysql` chưa có trong PATH):

```bash
mysql -u root -e "CREATE DATABASE energy_dss_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -u root energy_dss_v2 < database/energy_dss_v2.sql
```

- File không có `CREATE DATABASE` / `USE` / `DROP`: chỉ nạp vào DB đang chọn và **báo lỗi nếu bảng đã tồn tại** (không ghi đè).
- **Khôi phục dữ liệu demo** = backup, `DROP DATABASE energy_dss_v2`, rồi chạy lại 2 lệnh trên (**mất toàn bộ dữ liệu hiện có**).
- phpMyAdmin: tạo DB `energy_dss_v2` (collation `utf8mb4_general_ci`) → chọn DB → tab Import → file `database/energy_dss_v2.sql`, *Character set* = `utf8mb4`.
- Dữ liệu demo, schema, ràng buộc: [`docs/DATABASE.md`](docs/DATABASE.md).

## 8. Chạy ứng dụng

- URL: **`http://localhost/energy-dss/public`** (hoặc `http://localhost/energy-dss/`, `.htaccess` gốc tự chuyển vào `public/`).
- Đăng nhập `bql01` / `Password@123`. Kiểm tra nhanh: Kỹ thuật mở `/users` → **403**; đăng xuất rồi mở `/dashboard` → về `/login`.
- Demo 5–10 phút trên **DB demo riêng** (không đụng dữ liệu thật, email chỉ ghi log): [`docs/DEMO_FINAL.md`](docs/DEMO_FINAL.md).

**Email (FN-10.03)**: mặc định `MAIL_DRIVER=log` — không gửi ra Internet, chỉ ghi `storage/logs/mail.log` (email đã che) và lưu nội dung ở hộp thư đi. Gửi Gmail thật: đặt `MAIL_DRIVER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`, `MAIL_USERNAME` và **App Password** (Google → Bảo mật → Xác minh 2 bước → Mật khẩu ứng dụng) trong `.env`. Không dùng mật khẩu Gmail chính, không commit `.env`. Kiểm tra gửi thật một lần: `MAIL_TEST_RECIPIENT=<hộp thư test>` rồi `php tests/support/gmail_smoke.php`.

## 9. Chạy kiểm thử

```bash
TEST_SCHEMA=v2 bash tests/run_all_tests.sh
```

- `TEST_SCHEMA=v2` là mặc định (viết hay không đều được). Mọi DB tạm được dựng từ `database/energy_dss_v2.sql` (đúng 2 lệnh cài mới) rồi tự xoá; các suite "DB thật" chạy trên `energy_dss_v2` qua `php -S 127.0.0.1:8099`, email luôn ở chế độ `log`. Fingerprint dữ liệu người dùng được so trước / sau: test không để lại dữ liệu.
- Bao gồm: schema review + ràng buộc 27 bảng, thanh toán (đối soát, idempotency, đồng thời, HMAC, RBAC), FN-01 → FN-10.03 (service trên DB tạm + HTTP), test đồng thời nhiều tiến trình, toàn vẹn DB, bảo mật mọi route × vai trò, UI (Chrome headless), E2E, cài mới từ `energy_dss_v2.sql`, diễn tập demo, lint.
- Điều kiện: Apache + MySQL XAMPP đang chạy, MySQL `root` không mật khẩu, Chrome (cho test UI). Đường dẫn khác: `MYSQL_BIN=… PHP_BIN=… TEST_SCHEMA=v2 bash tests/run_all_tests.sh`.
- Chạy riêng: `TEST_SCHEMA=v2 bash tests/run_fn0<N>_tests.sh` (N = 1…9), `TEST_SCHEMA=v2 bash tests/run_demo_rehearsal.sh`.

## 10. Rollback

Cutover v1 → v2 ngày 04/10/2026 trên máy phát triển (script migrate: backup, preflight, migrate + 76 đối soát trong 1 transaction, `energy_dss` chỉ đọc).

- **Rollback**: đặt `DB_DATABASE=energy_dss` trong `.env`. Cùng mã nguồn chạy được trên cả hai schema (Repository tự nhận diện).
- `energy_dss` (schema v1) được giữ **nguyên trạng** từ lúc cutover; dữ liệu ghi vào `energy_dss_v2` sau đó **không** có trong `energy_dss`.
- Backup trước cutover, kết quả đối soát + ánh xạ id, script migrate, schema / seed v1: đã lưu trữ ở `~/energy-dss-archive/database-history/` (không thuộc bản chạy cuối).
- Bộ test chỉ còn chế độ v2; sau khi rollback, kiểm tra thủ công hoặc lấy lại các file v1 từ thư mục lưu trữ.

## 11. Giả định và giới hạn đã biết

Tóm tắt các điểm chính dưới đây; danh sách đầy đủ (giả định J / B / G…, ma trận 57 chức năng) nằm trong tài liệu đã lưu trữ (mục 12).

- **BLOCKED** FN-04.06 thay / reset công tơ (schema chưa lưu cặp chỉ số công tơ cũ / mới — B5 / J-11). Cách tạm: nhập chỉ số cuối công tơ cũ → BQL ngừng công tơ cũ, tạo công tơ mới → nhập chỉ số công tơ mới.
- **PARTIAL** (ví dụ): không "trả lại" chỉ số; không đề xuất phí cố định; không phiếu thay thế; ngưỡng một giá trị; báo cáo xuất CSV (chưa .xlsx); phiếu "PDF" là in từ trình duyệt.
- Điểm ảnh hưởng **số tiền** đang theo giả định, chờ BA chốt: VAT nước (J-07), bảo hiểm cháy nổ (J-08), nợ cũ / phí mở lại (J-09), làm tròn tới xu (J-15), sửa kỳ đã khóa (J-19). Ngưỡng cảnh báo seed 30% là giá trị UAT (J-32).
- **J-16** là BA Proposal (*not original confirmed requirement*); email là các assumption A-EMAIL-01…07 (gửi thủ công, không đính kèm, không gửi trùng).
- Cảnh báo ngưỡng đánh giá **theo từng công tơ** (cần baseline cấp công tơ). Dashboard / báo cáo của kỳ chưa khóa là số liệu "tạm".
- **Thanh toán**: không tích hợp ngân hàng thật — chỉ Mock Bank, ứng dụng giả lập độc lập (`http://localhost/mock-bank/`, gửi callback ký HMAC tới `http://localhost/energy-dss/public/payments/callback`; reset trước demo: `bash tools/reset_payment_demo.sh` — xem docs/DEMO_FINAL.md mục 4); callback của Energy DSS mặc định tắt (404), chỉ bật ở máy demo local qua `MOCK_BANK_*` trong `.env`; chỉ thanh toán kỳ đã khoá, đúng số tiền (không thanh toán một phần — sai số tiền chuyển "Cần kiểm tra"); QR tĩnh không chứa mã thanh toán nên người nộp ghi mã (hiện trên phiếu web) vào nội dung chuyển khoản; nợ cũ kỳ sau vẫn nhập tay (J-09).
- Không MFA, không giới hạn đăng nhập theo IP (chỉ khoá theo tài khoản). Production checklist: [`docs/SECURITY.md`](docs/SECURITY.md).

## 12. Tài liệu

| Tài liệu | Nội dung |
|---|---|
| [`docs/DEMO_FINAL.md`](docs/DEMO_FINAL.md) | Demo 5–10 phút (DB demo riêng) |
| [`docs/ARCHITECTURE_SUMMARY.md`](docs/ARCHITECTURE_SUMMARY.md) | Tóm tắt kiến trúc, DB v2, chiến lược test và rollback |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Schema v2 (27 bảng, gồm thanh toán), ánh xạ v1 → v2, ràng buộc, dữ liệu demo, lịch sử CSDL |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Kiểm soát bảo mật + production checklist |
| [`docs/UI_GUIDELINE.md`](docs/UI_GUIDELINE.md) | Quy ước giao diện: màu, chữ, nút, badge, bảng, form |
| [`docs/SUBMISSION_CHECKLIST.md`](docs/SUBMISSION_CHECKLIST.md) | Checklist nộp bài |

Tài liệu quá trình (nhật ký thiết kế theo từng bước `STEP2-3_design.md`, kiến trúc chi tiết, thiết kế 3NF + nhật ký migrate, kịch bản demo 22 bước, độ phủ 53 / 57 chức năng, giả định & khoảng trống, ghi chú thuyết trình, checklist ảnh chụp) đã lưu trữ ở `~/energy-dss-archive/docs-history/docs/` — không cần để chạy, hiểu hay demo bản cuối.
