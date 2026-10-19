# Gửi phiếu phí qua Zalo (ZNS)

## Cài đặt (làm 1 lần)
1. Chạy file `database/migrations/20261018_zalo_notifications.sql` trên database (phpMyAdmin → Import). Bản cài mới dùng `database/energy_dss_v2.sql` đã có sẵn bảng `zalo_notifications`.
2. Mặc định `.env` có `ZALO_DRIVER=log`: hệ thống **chỉ ghi nhận, không gửi ra ngoài** (dùng để demo). Tin xuất hiện trong menu **Hộp thư Zalo**.
3. Muốn gửi thật: đăng ký Zalo Official Account + ứng dụng ở Zalo for Developers, xin duyệt **mẫu ZNS "thông báo phí"**, rồi điền vào `.env`:
   `ZALO_DRIVER=zns`, `ZALO_APP_ID`, `ZALO_APP_SECRET`, `ZALO_ACCESS_TOKEN`, `ZALO_REFRESH_TOKEN`, `ZALO_TEMPLATE_BILL`.
   Khi thử, đặt `ZALO_MODE=development` (chỉ gửi tới số quản trị OA); để trống khi chạy thật.
4. Token tự làm mới và lưu ở `storage/zalo_token.json` (không commit file này).

## Cách dùng
- Trang phiếu phí của một căn: khối **Gửi qua Zalo** → chọn người nhận (chủ hộ/khách thuê có số điện thoại) → gửi.
- Trang chi tiết kỳ (đã khóa): **Gửi Zalo cho các hộ** gửi hàng loạt, không gửi trùng; **Gửi lại tin Zalo lỗi** gửi lại các tin thất bại.
- Menu **Hộp thư Zalo**: xem trạng thái đã gửi / lỗi / đang gửi, lọc, gửi lại.
- Quyền: BQL và Kế toán (`zalo_notifications.send` / `.view`).

## Lưu ý
- Số điện thoại lấy từ thông tin cư dân; hệ thống chuẩn hóa về dạng 84xxxxxxxxx, che bớt số với người không có quyền xem đầy đủ.
- Mẫu ZNS phải được Zalo duyệt trước; tham số mẫu cần khớp cấu hình trong `app/Services/ZaloNotificationService.php`. ZNS có thể tính phí theo từng tin do Zalo thu, cần kiểm tra bảng giá của Zalo.
- Kiểm thử logic (không cần mạng/DB): `php tests/zalo_client_test.php`.
