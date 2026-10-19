# Tóm tắt kiến trúc — Energy & Utility DSS (Topic 09)

Tài liệu ngắn cho người đánh giá. Chi tiết: [`DATABASE.md`](DATABASE.md), [`SECURITY.md`](SECURITY.md). (Kiến trúc chi tiết có sơ đồ Mermaid và thiết kế 3NF đầy đủ: đã lưu trữ ở `~/energy-dss-archive/docs-history/docs/`.)

## 1. Kiến trúc PHP

- **PHP 8.2 thuần**, không framework, không Composer (autoloader PSR-4 tự viết trong `bootstrap.php`). Chạy trên Apache (XAMPP) với front controller `public/index.php`; khi test dùng thêm `php -S`.
- Mô hình **MVC + Service + Repository**. Router, middleware, session, validator, xử lý lỗi đều tự viết trong `app/Core/`.
- Mỗi request: `.htaccess` → `public/index.php` (nạp `.env`, config, ErrorHandler, Session) → `Router` (`routes/web.php`) → middleware **auth → rbac:&lt;quyền&gt; → csrf** → Controller → Service → Repository → View.

## 2. Các tầng

| Tầng | Thư mục | Trách nhiệm | Không làm |
|---|---|---|---|
| Router + Middleware | `app/Core/Router.php`, `routes/web.php`, `app/Middleware/` | Khớp route; xác thực, phân quyền theo route (403 + audit), CSRF cho mọi POST | Nghiệp vụ |
| Controller (31) | `app/Controllers/` | Đọc input qua `Request`, gọi Service, redirect theo PRG, render view | SQL, nghiệp vụ |
| Service (51) | `app/Services/` | Validation, business rule, máy trạng thái (cảnh báo, kỳ, bản tính), transaction + khoá dòng, ghi audit | HTML, `$_POST` |
| Repository (28) | `app/Repositories/` | SQL bằng PDO prepared statement thật (`EMULATE_PREPARES = false`) | Nghiệp vụ |
| View | `resources/views/` | HTML; mọi giá trị qua `e()` | Truy vấn DB |
| Config | `config/` | Ma trận quyền, nhãn ENUM, tham số nghiệp vụ mặc định | Giá / ngưỡng (nằm trong DB) |

Nguyên tắc chung: không xoá cứng dữ liệu nghiệp vụ (chỉ "Ngừng"); mọi thay đổi ghi audit trong cùng transaction; form sửa có version token chống ghi đè; tiền tính bằng bcmath theo xu.

## 3. Cơ sở dữ liệu v2

- DB hiện hành **`energy_dss_v2`**, schema **27 bảng** (26 bảng 3NF + `payment_transactions`; DB thật thêm bảng thanh toán khi migrate có duyệt) (file duy nhất `database/energy_dss_v2.sql`: schema + dữ liệu demo), MariaDB 10.4 / MySQL 8, InnoDB, `utf8mb4`; **59 khoá ngoại, 18 CHECK có tên** (26 bảng 3NF: 57 / 14; thanh toán: +2 / +4).
- So với schema cũ (v1, 29 bảng), 6 bảng được gộp thành 3; 23 bảng còn lại giữ nguyên cột và id:

| v1 | v2 |
|---|---|
| `tariffs`, `tariff_tiers`, `tariff_proposals`, `fixed_fee_rates` | `pricing_plans` (biểu giá, phí cố định, đề xuất — phân biệt bằng `price_type`, `origin`, `approval_status`) + `pricing_tiers` |
| `alert_status_history`, `alert_contact_logs` | `alert_events` (`event_type` = `state_change` / `contact`) |
| `recommendation_rules.condition_params` (JSON) | cột `n_periods`, `utility_type` |

- Duyệt đề xuất giá ở v2: **chính dòng đề xuất** trở thành phiên bản hiệu lực (không nhân bản dữ liệu giá).

## 4. Tương thích ở tầng Repository

Mục tiêu khi chuyển sang v2: **không đổi Service / Controller / View**, để hành vi nghiệp vụ giữ nguyên.

- `App\Core\Schema::isV2()` nhận diện schema (có bảng `pricing_plans` hay không), cache theo tên DB.
- 6 Repository bị ảnh hưởng (`TariffRepository`, `FixedFeeRepository`, `TariffProposalRepository`, `AlertRepository`, `AlertContactLogRepository`, `RecommendationRuleRepository`) đọc từ **bảng dẫn xuất** trả về **đúng dạng dòng v1** (cùng tên cột, cùng thứ tự), và ánh xạ cột khi ghi.
- Ngoại lệ duy nhất ở Service: `TariffProposalService::approve()` có nhánh v2 (duyệt tại chỗ); audit giữ nguyên.
- Cùng một mã nguồn chạy được trên `energy_dss` (v1) và `energy_dss_v2` — đây là cơ sở của rollback.
- Đã so sánh hành vi chỉ-đọc giữa hai DB (`tests/v2_behaviour_compare.php`): giống hệt, trừ id phí cố định được đánh số lại (số tiền không đổi) và khoảng trắng JSON của 2 quy tắc seed.

## 4b. Thanh toán (Payment module)

- **1 bảng mới `payment_transactions`** (giao dịch ngân hàng + kết quả đối soát); **không thêm cột vào `apartment_charges`**, không ghi vào bản tính (kể cả kỳ đã khoá).
- **Trạng thái thanh toán tính từ giao dịch** (không lưu, giữ 3NF): *Đã thanh toán* = tổng giao dịch đã ghi nhận đúng bằng số phải thu; *Cần kiểm tra* = còn giao dịch sai số tiền / không rõ mã / kỳ chưa khoá / trả trùng, hoặc đã thu ≠ phải thu (vd kỳ mở lại và tính lại); còn lại *Chưa thanh toán*.
- **Mã thanh toán** suy ra từ id bản tính (`EDSS000012`), in trên phiếu web; đối soát đọc mã trong nội dung chuyển khoản. Chỉ thanh toán kỳ **đã khoá**, **đúng số tiền** (không thanh toán một phần).
- **Idempotency**: `transaction_id` UNIQUE — callback lặp (kể cả đồng thời) không xử lý lại. **Đồng thời**: khoá kỳ (chia sẻ) → bản tính (ghi), đọc số đã ghi nhận bằng *locking read* — 2 giao dịch cùng bản tính không thể cùng được ghi nhận.
- **Mock Bank** = ứng dụng giả lập **độc lập** (`tools/mock-bank/`, phục vụ riêng tại `http://localhost/mock-bank/` chỉ cho máy local, không dùng mã / đăng nhập / quyền của Energy DSS, không có trong menu Energy DSS): ký payload **HMAC-SHA256** và gọi `POST /payments/callback` của Energy DSS; chỉ gửi tới callback trên máy local. Callback **tắt mặc định** (404) — chỉ bật ở máy demo local (`MOCK_BANK_ENABLED=1` + `MOCK_BANK_SECRET` trong `.env`); reset dữ liệu demo: `tools/reset_payment_demo.sh`.
- **Email xác nhận thanh toán** (`payment_confirmation`) gửi sau commit, **1 lần / bản tính**; tách khỏi đếm / gửi lại hàng loạt phiếu phí (lọc theo loại thư). Mở lại kỳ đã có thanh toán: cảnh báo + bắt buộc xác nhận (không chặn cứng).
- Quyền: xem (BQL, Kế toán), xử lý giao dịch cần kiểm tra (Kế toán); Mock Bank là ứng dụng riêng, không có quyền trong Energy DSS; Kỹ thuật không có quyền thanh toán.

## 5. Luồng dữ liệu chính

```
Danh mục (FN-02) + Biểu giá (FN-03)
        │
Chỉ số công tơ: nhập / import Excel (FN-04) → Duyệt ──► kiểm tra J-16 (căn khai báo không phát sinh nhưng CL > 0)
        │
Tính phí theo bậc + phí cố định (FN-05) → Xác nhận (bốn mắt; J-16 đang mở chặn xác nhận) → Khóa kỳ
        │
Baseline / So sánh / Mục tiêu (FN-06) → Đánh giá ngưỡng → Cảnh báo kèm lý do (FN-07)
        │
Con người: review → kiểm tra hiện trường → kết luận → đóng (máy trạng thái, audit)
        │
Khuyến nghị theo quy tắc (FN-08) → Dashboard, báo cáo, phiếu phí (FN-09) → Email phiếu theo kỳ đã khóa (FN-10.03)
```

Hệ thống không tự chẩn đoán và không tự thay đổi chỉ số / trạng thái căn / tiền; mọi quyết định do người có quyền thực hiện.

## 6. Chiến lược kiểm thử

- Một lệnh: `TEST_SCHEMA=v2 bash tests/run_all_tests.sh` (lần gần nhất 04/10/2026: 2.171/2.171 kiểm tra PASS, lint 0 lỗi, exit 0).
- **Service test** trên **DB tạm** dựng mới mỗi lần từ `database/energy_dss_v2.sql` (đúng 2 lệnh cài mới của README), tự xoá sau khi chạy.
- **HTTP test** qua server thật (Apache, hoặc `php -S` cho DB tạm / v2): đăng nhập, RBAC mọi route × vai trò, CSRF, fuzz input, file nhạy cảm, phiên, PII.
- **Đồng thời**: nhiều tiến trình PHP cùng thao tác (duyệt, import, tính phí, khóa kỳ, duyệt đề xuất giá…) để kiểm khoá dòng và chống deadlock.
- **UI**: Chrome headless (cập nhật không tải lại trang, trang đăng nhập, gửi email theo kỳ).
- **Cài mới**: đúng 2 lệnh của README nạp `database/energy_dss_v2.sql` vào DB rỗng → 27 bảng, 3 tài khoản demo, nạp lại bị từ chối (không ghi đè), app smoke. (Test nâng cấp schema v1 → patch 001 → 005 → migrate v2 đã lưu trữ cùng các file v1.)
- **Diễn tập demo** trên DB cài mới; **lint** mọi file PHP.
- Cô lập dữ liệu thật: test chỉ tạo dữ liệu có đánh dấu lượt chạy và tự dọn; **fingerprint** dữ liệu người dùng được so trước / sau; email luôn ở chế độ `log` / `array` (không gửi thật).

## 7. Chiến lược rollback

- Cutover 04/10/2026 bằng script migrate (nay đã lưu trữ ở `~/energy-dss-archive/database-history/database/migrations/v2/`): fingerprint + CHECKSUM nguồn trước / sau, backup, preflight, migrate + **76 đối soát** (số dòng, tổng tiền tới xu, bậc giá, dòng thời gian cảnh báo, email, audit) trong **một transaction**; lỗi → ROLLBACK và xoá DB đích.
- `energy_dss` (v1) giữ **nguyên trạng** làm bản rollback; backup trước cutover và ánh xạ id cũ → mới (`id_map.csv`) đã lưu trữ cùng thư mục trên (`docs/DATABASE.md` mục 6).
- Rollback = đặt `DB_DATABASE=energy_dss` trong `.env` (mã nguồn tự nhận diện schema). Giới hạn: dữ liệu ghi vào v2 sau cutover không có trong v1.
