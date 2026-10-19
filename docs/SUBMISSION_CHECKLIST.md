# Checklist nộp bài — Energy & Utility DSS (Topic 09, Nhóm 2)

Trạng thái kiểm tra ngày **04/10/2026** trên máy phát triển (XAMPP macOS, PHP 8.2.4, MariaDB 10.4.28).
`[x]` = đã kiểm, `[ ]` = nhóm cần tự làm trước khi nộp. Cột "Cách kiểm" để kiểm lại trên máy khác.

## 1. Ứng dụng và cơ sở dữ liệu

| | Mục | Cách kiểm | Kết quả 04/10/2026 |
|---|---|---|---|
| [x] | Ứng dụng chạy | Mở `http://localhost/energy-dss/public/login` | HTTP 200 |
| [x] | Kết nối DB đúng DB hiện hành | `.env`: `DB_DATABASE=energy_dss_v2`; đăng nhập thành công ghi `last_login_at` vào `energy_dss_v2` | Đúng (smoke cutover 25/25) |
| [x] | Đăng nhập hoạt động | Đăng nhập `bql01` / `Password@123` → `/dashboard`; Kỹ thuật mở `/users` → 403 | Đúng |
| [x] | Tài khoản demo có sẵn | `SELECT username, role, status FROM energy_dss_v2.users` | `bql01` (bql), `kythuat01` (ky_thuat), `ketoan01` (ke_toan) — đều `hoat_dong` |
| [x] | Schema v2 đang dùng | `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='energy_dss_v2'` | **26** bảng trên DB thật (bảng `payment_transactions` chờ migrate có duyệt); file `database/energy_dss_v2.sql` = **27** bảng |
| [x] | Dữ liệu seed / demo có sẵn | `energy_dss_v2`: kỳ, căn hộ, biểu giá | Kỳ 06/2024, 07/2024 (đã khóa), 07/2026 (mở); 8 căn; 11 dòng giá |
| [x] | DB demo riêng dựng được (không đụng dữ liệu thật) | `TEST_SCHEMA=v2 bash tests/support/create_test_db.sh energy_dss_demo` ([`DEMO_FINAL.md`](DEMO_FINAL.md) mục 0) | 26 bảng, đăng nhập + 8 màn hình chính 200 |
| [x] | Rollback sẵn sàng | DB `energy_dss` giữ nguyên; backup trước cutover lưu trữ ở `~/energy-dss-archive/database-history/database/backups/` | `energy_dss` CHECKSUM 29 bảng không đổi sau cutover |

## 2. Kiểm thử

| | Mục | Cách kiểm | Kết quả |
|---|---|---|---|
| [x] | Bộ test chạy được | `TEST_SCHEMA=v2 bash tests/run_all_tests.sh` (~40 phút; cần Apache + MySQL XAMPP, Chrome cho test UI) | 04/10/2026: **2.171/2.171 PASS**, lint 294 file 0 lỗi, **exit 0** |
| [x] | Test không để lại dữ liệu / tài nguyên | Fingerprint `energy_dss` + `energy_dss_v2` trước / sau; `SHOW DATABASES`; `lsof -iTCP -sTCP:LISTEN` | Fingerprint `[]`; không còn DB tạm; không còn server `php -S` |
| [x] | Test không gửi email thật | Test dùng `MAIL_DRIVER=log` / `array`; server test ép `log` | Đúng |

## 3. Tài liệu

| | Mục | File |
|---|---|---|
| [x] | README cập nhật (v2, cài đặt, chạy, test, rollback, giới hạn) | [`README.md`](../README.md) |
| [x] | Demo 5–10 phút | [`DEMO_FINAL.md`](DEMO_FINAL.md) |
| [x] | Tóm tắt kiến trúc | [`ARCHITECTURE_SUMMARY.md`](ARCHITECTURE_SUMMARY.md) |
| [x] | CSDL v2 + ánh xạ v1 → v2 | [`DATABASE.md`](DATABASE.md) |
| [x] | Giả định, giới hạn | README mục 11 (tài liệu chi tiết đã lưu trữ ở `~/energy-dss-archive/docs-history/docs/`) |
| [x] | File CSDL duy nhất + lịch sử CSDL ghi rõ | `database/energy_dss_v2.sql`; file v1 / patch / migrate / backup / fixture (đã ghi nhãn *reconstructed*) lưu trữ — xem [`DATABASE.md`](DATABASE.md) mục 6 |

## 4. Bảo mật và vệ sinh repo

| | Mục | Cách kiểm | Kết quả |
|---|---|---|---|
| [x] | Không lộ bí mật | `.env` nằm trong `.gitignore` và bị `.htaccess` chặn qua web; `.env.example` không có mật khẩu; quét repo (trừ `.env`) không có `MAIL_PASSWORD=` / `DB_PASSWORD=` có giá trị | Đúng |
| [ ] | **Không nộp `.env` thật** | Khi nén / nộp bài: loại `.env` (chứa cấu hình Gmail của máy phát triển); người chấm tạo `.env` từ `.env.example` | Nhóm tự kiểm khi đóng gói |
| [x] | Không có file tạm | Tìm `.DS_Store`, `*.tmp`, `*.bak`, `*.swp`, `*~` ngoài `storage/` | Không có; `storage/imports`, `storage/exports` chỉ có `.gitkeep` |
| [ ] | Log runtime | `storage/logs/app.log`, `mail.log` là log chạy thật / test (đã `.gitignore`); không chứa mật khẩu (email đã che) | Cân nhắc **không đưa vào bản nộp** hoặc làm trống trước khi nén |
| [x] | Không có dữ liệu thật trong `database/` | `database/` chỉ có `energy_dss_v2.sql` (dữ liệu demo tổng hợp); backup dữ liệu thật đã chuyển ra `~/energy-dss-archive/` | Đúng — không đưa thư mục lưu trữ vào bản nộp |

## 5. Minh chứng demo

| | Mục | Ghi chú |
|---|---|---|
| [ ] | Ảnh chụp màn hình | Theo các bước của [`DEMO_FINAL.md`](DEMO_FINAL.md) (danh sách ảnh chi tiết cũ: `~/energy-dss-archive/docs-history/docs/SCREENSHOT_CHECKLIST.md`); nên chụp trên DB demo (`DEMO_FINAL.md`) để không lộ dữ liệu thật |
| [ ] | Diễn tập demo 1 lần trước buổi trình bày | Làm đúng [`DEMO_FINAL.md`](DEMO_FINAL.md) mục 0 → 1; bản tự động tương đương: `TEST_SCHEMA=v2 bash tests/run_demo_rehearsal.sh` |
| [ ] | Kết quả test đính kèm | Lưu output `TEST_SCHEMA=v2 bash tests/run_all_tests.sh` (dòng `TỔNG: PASS`) vào báo cáo nếu cần |
