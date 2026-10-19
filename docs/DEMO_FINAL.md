# Demo 5–10 phút — Energy & Utility DSS (Topic 09)

Mục tiêu: trình bày trọn chuỗi **dữ liệu → tính phí → so sánh → cảnh báo → con người xem xét → khuyến nghị → email** trong 5–10 phút.

> **Demo chạy trên DB demo riêng `energy_dss_demo`**, không dùng `energy_dss_v2` (dữ liệu thật) và không sửa `.env`.
> Server demo dùng `tests/support/review_router.php`, router này **luôn đặt email ở chế độ `log`**: không gửi Gmail thật dù `.env` cấu hình SMTP.
> Các bước và con số dưới đây lấy từ hai kịch bản đã kiểm tự động: `tests/demo_rehearsal_test.php` (44 kiểm tra) và `tests/j16_http_test.php` (J-16).

## 0. Chuẩn bị (trước giờ demo, ~2 phút)

Ở thư mục project, bằng bash (macOS: `export PATH=/Applications/XAMPP/bin:$PATH`):

```bash
# 1) DB demo 27 bảng từ database/energy_dss_v2.sql (cùng cách bộ test dựng DB tạm). Chạy lại = khôi phục dữ liệu demo.
TEST_SCHEMA=v2 bash tests/support/create_test_db.sh energy_dss_demo
# 2) Server demo (giữ cửa sổ terminal này mở)
PHP_CLI_SERVER_WORKERS=4 REVIEW_DB=energy_dss_demo REVIEW_URL=http://127.0.0.1:8080 \
  php -S 127.0.0.1:8080 -t public tests/support/review_router.php
```

- URL demo: **`http://127.0.0.1:8080`**.
- Mở 3 cửa sổ trình duyệt **tách phiên** (vd Chrome thường, Chrome ẩn danh, Firefox): A = `bql01` (BQL), B = `kythuat01` (Kỹ thuật), C = `ketoan01` (Kế toán). Mật khẩu chung `Password@123`.
- Để sẵn file `docs/demo/chi_so_07_2024_demo.xlsx`.
- Dữ liệu seed: kỳ **06/2024 Đã khóa** (lịch sử), kỳ **07/2024 Mở** chưa có chỉ số; căn **CC-2306** khai báo "Không phát sinh điện nước".

Sau demo: `Ctrl+C` để dừng server, rồi `mysql -u root -e "DROP DATABASE energy_dss_demo"`.

## 1. Kịch bản

| # | Phút | Vai trò | Màn hình | Thao tác | Thuyết trình | Kết quả mong đợi |
|---|---|---|---|---|---|---|
| 1 | 0:00 | BQL (A) | `/login` → **Dashboard** | Đăng nhập `bql01` | 3 vai trò; menu chỉ hiện mục có quyền; RBAC kiểm tra ở từng route | Dashboard theo vai trò; kỳ 07/2024 chưa có số liệu |
| 2 | 0:40 | BQL | **Danh mục → Căn hộ → BT1**; rồi **CC-2306** → cư dân **Lê Thị D** → Sửa | Xem BT1; nhập Email `le.thi.d@example.invalid` cho Lê Thị D → Lưu | Danh mục là nền cho tính phí; không xoá cứng, chỉ "Ngừng"; CC-2306 đang khai báo **"Không phát sinh điện nước"** | BT1: 80 m², công tơ BT1-DIEN / BT1-NUOC, chủ hộ Phạm Văn A, 1 ô tô. Lê Thị D có email |
| 3 | 1:30 | BQL | **Biểu giá điện/nước → Điện – Sinh hoạt**; **Phí cố định & gửi xe** | Xem | Giá lưu trong DB theo **phiên bản ngày hiệu lực**, không hardcode; Kế toán đề xuất, BQL duyệt | 6 bậc 1.806 → 3.151 đ/kWh, VAT 8%; 7 loại phí |
| 4 | 2:00 | Kỹ thuật (B) | **Kỳ ghi chỉ số → 07/2024 → Nhập chỉ số** → chọn **BT1-DIEN** | Nhập CSC **1200** → Lưu; rồi **1800** → "Lưu & nhập công tơ tiếp theo"; **BT1-NUOC** nhập **232** | Validation ở server; tiêu thụ do server tính | 1200 bị chặn (nhỏ hơn CSĐ 1343). 1800 → CL **457 kWh**, BT1-NUOC → **16 m³**, trạng thái Nháp |
| 5 | 2:45 | Kỹ thuật | **Kỳ 07/2024 → Import Excel** | Tải `chi_so_07_2024_demo.xlsx` → "Tải lên & kiểm tra" → "Xác nhận lưu". Sau đó **Chỉ số công tơ** (`/readings`) → chỉ số **CC2306-DIEN** kỳ 07/2024 (Nháp) → **Sửa** → CSC **25**, lý do "Đọc lại tại hiện trường" → Lưu | Import có xem trước, kiểm tra từng dòng, đối chiếu dòng Tổng; chỉ số Nháp còn sửa được trước khi duyệt | 3 dòng hợp lệ, 0 lỗi, control total khớp → đủ **8/8** công tơ. CC2306-DIEN: CL **25 kWh** |
| 6 | 3:30 | BQL (A) | **Kỳ 07/2024 → Duyệt chỉ số** | Chọn tất cả → **"Duyệt các chỉ số đã chọn"** | Người nhập ≠ người duyệt; chỉ chỉ số đã duyệt mới được dùng. Khi duyệt, hệ thống tự kiểm tra **J-16** | 8 chỉ số **Đã xác nhận**; tự sinh **1 cảnh báo J-16** cho CC-2306: *"Phát sinh tiêu thụ tại căn khai báo không sử dụng"* |
| 7 | 4:00 | Kế toán (C) | **Kỳ 07/2024 → Tính phí** → **"Tính phí cả kỳ"**; mở bản tính **BT1**, rồi **CC-2306** | Xem chi tiết; thử xác nhận CC-2306 | Tính theo xu (bcmath), giải trình từng bậc. J-16 **chặn xác nhận** bản tính của căn đang được xác minh | 4 bản tính. BT1: điện 457 kWh = **1.157.807 đ** + VAT **92.624,56 đ**, nước **185.120 đ**, phát sinh **2.331.791,56 đ**. CC-2306: thẻ "Đang BQL xác minh", nút Xác nhận bị khoá |
| 8 | 4:45 | BQL | **Cảnh báo** → lọc loại J-16 → mở cảnh báo CC-2306 | Đọc 7 khối (Phát hiện, Dữ liệu công tơ, Trạng thái căn, Liên hệ cư dân, Kết quả Kỹ thuật, Kết luận BQL, Timeline) → **"Review"** (ưu tiên Cao) → **"Gửi email xác minh"** → ghi nhận cuộc gọi → Kết luận **"C. Tiêu thụ thực tế được xác nhận"** + căn cứ → **"Lưu kết luận"** | J-16 là **BA Proposal**: hệ thống chỉ báo bất nhất, **không kết luận nguyên nhân**; con người xác minh và quyết định | Cảnh báo **Đã xác nhận**; email xác minh trạng thái *Đã gửi* (transport `log`); timeline đủ các bước |
| 9 | 6:00 | BQL | **Mức nền (Baseline) → Thêm**; rồi **Cảnh báo → kỳ 07/2024 → "Đánh giá ngưỡng"** | Phạm vi **Công tơ BT1-DIEN**, phương pháp **Kỳ trước liền kề**, hiệu lực 01/07/2024 → Lưu; chạy đánh giá | Ngưỡng là chính sách của BQL (30% là giá trị UAT); cảnh báo đánh giá theo từng công tơ | Baseline **343 kWh**; đúng **1 cảnh báo ngưỡng**: BT1-DIEN **+33,24%** (Mới). J-16 không bị sinh trùng |
| 10 | 7:00 | BQL | Chi tiết cảnh báo BT1-DIEN | Đọc **"Vì sao có cảnh báo?"** → **"Sinh khuyến nghị"** | Khuyến nghị dựa trên **quy tắc** BQL cấu hình, không AI; nội dung lưu snapshot | Khuyến nghị **"Đề xuất kiểm tra công tơ"** (quy tắc "vượt ngưỡng 1 kỳ") kèm lý do khớp |
| 11 | 7:45 | Kế toán (C) | **Kỳ 07/2024 → Xác nhận tính phí**; rồi trang kỳ → **"Khóa kỳ"** | Chọn tất cả → "Xác nhận các bản đã chọn" → khóa kỳ | Kiểm tra bốn mắt; J-16 đã có kết luận nên CC-2306 xác nhận được; kỳ khóa thì mọi sửa bị chặn | 4 bản **Đã xác nhận**; kỳ **Đã khóa** |
| 12 | 8:30 | Kế toán | Trang **kỳ 07/2024** → **"Gửi Gmail cho các hộ"**; rồi **Hộp thư đi** `/email-notifications` | Xác nhận gửi; mở lịch sử, xem nội dung một thư | Gửi **thủ công** theo kỳ đã khóa, không gửi trùng; chế độ demo chỉ ghi log | Nút đổi thành **"✓ Đã gửi Gmail"**; hộp thư đi có **2 thư** tới Lê Thị D (email xác minh J-16 + phiếu thông báo phí), trạng thái Đã gửi |
| 13 | 9:15 | BQL | **Dashboard** (kỳ 07/2024) | Xem | Dashboard tổng hợp tiêu thụ, chi phí (Chính thức vì kỳ đã khóa), cảnh báo, khuyến nghị | Số liệu kỳ 07/2024 "Chính thức"; 1 khuyến nghị; cảnh báo BT1 còn mở chờ xử lý |
| 14 | (tuỳ chọn) | Kế toán (C) | Trên **luồng local chính** (mục 4): Kế toán mở `http://localhost/energy-dss/public/payments` (kỳ 07/2026); tab riêng mở **Mock Bank độc lập `http://localhost/mock-bank/`** (không cần đăng nhập) → mã `EDSS000543` (Rs02) + `1.996.240` → **Gửi callback**; gửi lại cùng mã giao dịch; rồi một giao dịch sai số tiền → **Cần kiểm tra** → Bỏ qua (lý do) | Thanh toán tính từ giao dịch ngân hàng đã đối soát, chỉ khớp đúng số tiền, callback lặp không xử lý lại; ngân hàng chỉ là giả lập | Rs02 **Đã thanh toán** trên **Dashboard thanh toán** (F5); email xác nhận gửi thật tới hộp thư test; lần gửi lại: "không xử lý lại (idempotent)" |

Tổng: ~10 phút (+2 phút nếu demo thanh toán). Rút xuống ~6 phút: bỏ bước 3, bỏ phần nhập 1200 ở bước 4, và bỏ bước 13.

## 2. Thông điệp chính (nói khi kết thúc)

1. **Hệ thống không tự chẩn đoán**: cảnh báo luôn có lý do (giá trị, mốc so sánh, ngưỡng / quy tắc) và chỉ là tín hiệu cần xem xét.
2. **Con người quyết định**: review, kiểm tra hiện trường, kết luận, đóng — mọi bước có audit (ai, khi nào, cũ → mới).
3. **Khuyến nghị theo quy tắc minh bạch**, BQL bật / tắt / sửa được.
4. **Tiền tính chính xác tới xu**, kỳ đã khóa không sửa được; mọi số liệu dựa trên chỉ số đã duyệt.
5. **Dữ liệu chạy trên schema 27 bảng** (26 bảng 3NF + thanh toán, `energy_dss_v2`); `energy_dss` giữ để rollback.

## 3. Dự phòng

| Sự cố | Cách xử lý |
|---|---|
| Không upload được Excel | Kỹ thuật nhập tay 6 chỉ số còn lại theo bảng dưới (CC2306-DIEN nhập **25**) |
| Thao tác sai | Dừng server, chạy lại mục 0 (dựng lại DB demo), bắt đầu lại |
| Cổng 8080 bận | Đổi cả `127.0.0.1:8080` và `REVIEW_URL` sang cổng khác (vd 8090) |
| Không có Internet | Không ảnh hưởng: không CDN, không API ngoài, email ở chế độ log |

| Công tơ | CSĐ (06/2024) | CSC 07/2024 | Tiêu thụ |
|---|---|---|---|
| BT1-DIEN | 1343 | 1800 | 457 kWh |
| BT1-NUOC | 216 | 232 | 16 m³ |
| S01-DIEN | 650 | 800 | 150 kWh |
| S01-NUOC | 130 | 160 | 30 m³ |
| CC2305-DIEN | 450 | 600 | 150 kWh |
| CC2305-NUOC | 62 | 74 | 12 m³ |
| CC2306-DIEN | 0 | **25** (sửa sau import) | 25 kWh |
| CC2306-NUOC | 0 | 0 | 0 |

Ghi chú: vì CC-2306 có 25 kWh, tổng tiền toàn tòa khác con số 5.155.285,56 đ của kịch bản 22 bước (kịch bản đó không có J-16). Số của BT1 không đổi.

## 4. Demo thanh toán trên luồng local chính (Apache + `energy_dss_v2`)

- Energy DSS: `http://localhost/energy-dss/public/payments` — Mock Bank (độc lập, chỉ máy local): `http://localhost/mock-bank/` (`htdocs/mock-bank` → `tools/mock-bank`).
- Callback: `POST http://localhost/energy-dss/public/payments/callback` — bật nhờ `MOCK_BANK_ENABLED=1` + `MOCK_BANK_SECRET` trong `.env` máy này; Mock Bank đọc cùng secret từ `tools/mock-bank/config.local.php` (web không truy cập được). Tắt: xoá 2 dòng `MOCK_BANK_*` trong `.env` → callback 404.
- Ca demo: **Rs02 — `EDSS000543` — `1.996.240` đ** (kỳ 07/2026 đã khoá; email xác nhận chỉ tới hộp thư test). Sai số tiền / mã lạ → **Cần kiểm tra**.
- Trước buổi trình bày: `bash tools/reset_payment_demo.sh` — chỉ xoá giao dịch Mock Bank + email xác nhận thanh toán của chúng (lưu bản sao vào `~/energy-dss-archive/payment-demo-resets/`), không đụng bản tính / chỉ số / kỳ; mọi phiếu tự về **Chưa thanh toán**.
