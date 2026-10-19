# UI guideline — Energy & Utility DSS

Phong cách: **enterprise, sạch, ít màu**. Nền trung tính và **một** màu nhấn; màu trạng thái chỉ dùng để truyền nghĩa, không dùng để trang trí.
Mọi giá trị được khai báo thành biến CSS trong `public/assets/css/app.css` (`:root`). Không dùng thư viện ngoài, không dùng font tải qua mạng (chạy offline).

## 1. Bảng màu

| Vai trò | Token | Giá trị | Dùng cho |
|---|---|---|---|
| Nền trang | `--c-bg` | `#f4f6fa` | Nền vùng nội dung |
| Bề mặt | `--c-surface` | `#ffffff` | Card, panel, bảng, form |
| Bề mặt phụ | `--c-surface-2` | `#f8fafc` | Header bảng, zebra, ô readonly |
| Viền | `--c-border` / `--c-border-strong` | `#e4e8ef` / `#cfd6e1` | Viền card, ô nhập |
| Chữ | `--c-text` / `--c-text-2` / `--c-muted` | `#101828` / `#344054` / `#667085` | Tiêu đề / nội dung / phụ |
| Nhấn (primary) | `--c-primary` | `#2b59c3` (hover `#2248a3`, nền nhạt `#eef3fc`) | Nút chính, link, active, focus |
| Sidebar | `--c-nav` | `#0f1729` | Nền menu trái |
| Điện | `--c-electric` | `#c2410c` (nền nhạt `#fff4ec`) | KPI, biểu đồ điện |
| Nước | `--c-water` | `#0e7490` (nền nhạt `#ecfaff`) | KPI, biểu đồ nước |

**Màu trạng thái** (badge, thông báo). Nghĩa của từng màu là cố định trên toàn hệ thống:

| Nghĩa | Lớp | Chữ / nền | Ví dụ trạng thái |
|---|---|---|---|
| Hoàn tất / đang hiệu lực | `badge--active` | `#067647` / `#ecfdf3` | Kỳ Mở, chỉ số Đã xác nhận, bản tính Đã xác nhận, cảnh báo Đã xác nhận, mục tiêu Đạt, đang áp dụng |
| Đang chờ / cần làm tiếp | `badge--warning` | `#b54708` / `#fffaeb` | Chỉ số Nháp, Cần kiểm tra lại, Chờ duyệt, Mở lại kỳ, ưu tiên Trung bình |
| Cần chú ý / lỗi | `badge--danger` | `#b42318` / `#fef3f2` | Cảnh báo Mới, ưu tiên Cao, Bị trả lại, CẦN TÍNH LẠI, Không đạt |
| Đang xử lý / thông tin | `badge--info` | `#175cd3` / `#eff8ff` | Đã review, Đã tính, Sắp áp dụng |
| Đã đóng / lịch sử | `badge--inactive`, `badge--muted` | `#475467` / `#f2f4f7` | Kỳ Đã khóa, Đã đóng, Ngừng, Chưa tính |

## 2. Chữ

- Font: `Inter` nếu máy đã cài, nếu không dùng font hệ thống (`system-ui`, `-apple-system`, `Segoe UI`, `Roboto`). Không tải font từ mạng.
- Số liệu dùng `font-variant-numeric: tabular-nums` để các cột số thẳng hàng.

| Cấp | Cỡ / độ đậm | Dùng cho |
|---|---|---|
| Display | 30 / 700 | Số KPI |
| H1 | 22 / 700 | Tiêu đề trang |
| H2 | 15 / 600 | Tiêu đề panel |
| Body | 14 / 400, line-height 1.55 | Nội dung, bảng |
| Small | 13 | Hint, breadcrumb |
| Caption | 12 / 600, viết hoa, giãn chữ | Header bảng, nhãn KPI, nhóm menu |

## 3. Khoảng cách

Thang 4 px: `4 · 8 · 12 · 16 · 20 · 24 · 32 · 40`.
- Nội dung trang: padding 28 px (desktop), 16 px (mobile); bề rộng tối đa 1440 px.
- Panel: padding 20–24 px.
- Khoảng cách giữa các panel: 20 px.
- Form: mỗi nhóm cách nhau 16 px; nhãn cách ô nhập 6 px.

## 4. Thành phần

| Thành phần | Quy ước |
|---|---|
| **Card / panel** | Nền trắng, viền 1 px `--c-border`, bo góc 12 px, bóng rất nhẹ (`0 1px 2px`). Tiêu đề panel 15 px / 600 |
| **KPI (`stat-card`)** | Nhãn caption ở trên, số 30 px đậm, dòng phụ 12–13 px màu muted. KPI điện / nước có vạch màu bên trái (`stat-card--electric` / `--water`) |
| **Nút** | Cao 38 px (`btn--sm` 32 px), bo 8 px, chữ 14 / 600. 4 loại: **primary** (nền nhấn), **secondary** (nền trắng, viền), **ghost** (chữ nhấn, viền nhạt), **danger** (đỏ). Hover đậm hơn; focus có vòng 3 px màu nhấn. Mỗi vùng chỉ có 1 nút primary |
| **Badge** | Dạng viên thuốc, chữ 12 / 600, có chấm màu bên trái; màu theo bảng trạng thái mục 1 |
| **Bảng** | Header nền `--c-surface-2`, chữ caption; dòng cao ~44 px; zebra rất nhạt; hover tô nền nhấn nhạt; cột số căn phải; link hành động gọn ở cột cuối |
| **Form** | Nhãn 13 / 600 màu `--c-text-2`; ô nhập cao 40 px, viền `--c-border-strong`, focus viền nhấn + vòng mờ; hint 12 px muted; lỗi: viền đỏ + chữ đỏ 12 px có biểu tượng ⚠ |
| **Thông báo** | Nền nhạt + viền trái 3 px theo màu trạng thái |
| **Empty state** | Biểu tượng tròn nhạt + một câu ngắn, lịch sự, căn giữa |
| **Breadcrumb** | 13 px muted, phân cách `›`, mục hiện tại đậm |
| **Sidebar** | Nền tối, nhóm menu có nhãn caption; mục active có nền sáng hơn + vạch nhấn bên trái |

## 5. Responsive

- Từ 1280 px trở lên: bố cục đầy đủ.
- 861–1279 px (laptop nhỏ): lưới KPI tự co.
- ≤ 860 px (tablet / mobile): sidebar trượt ra từ trái (nút ☰), có lớp phủ, chạm ra ngoài để đóng; bảng cuộn ngang; panel chiếm toàn bộ chiều ngang.

## 6. In

Phiếu thông báo và báo cáo in trên nền trắng, ẩn menu / header / bộ lọc, mỗi phiếu một trang, giữ viền bảng.
