-- Làm sạch chữ hiển thị trong dữ liệu: bỏ mã nghiệp vụ (FN-xx, BR-xx, J-xx...) và sửa các dòng bị lỗi dấu "?????".
-- Chạy 1 lần trong phpMyAdmin (tab SQL) trên database energy_dss. An toàn: chỉ sửa chữ mô tả, không đổi số liệu.
SET NAMES utf8mb4;

UPDATE alert_rules
   SET name = 'Nước vượt baseline > 30%'
 WHERE id = 2 AND (name LIKE '%?%' OR name LIKE '%J-32%');

UPDATE system_configs SET description = 'Tự động đăng xuất sau 30 phút không hoạt động'
 WHERE config_key = 'session_timeout_minutes';
UPDATE system_configs SET description = 'Khóa tạm sau 5 lần đăng nhập sai'
 WHERE config_key = 'max_failed_login_attempts';
UPDATE system_configs SET description = 'Thời gian khóa tài khoản tạm thời (phút)'
 WHERE config_key = 'account_lock_duration_minutes';
UPDATE system_configs SET description = '0 = cho phép lưu một phần (dòng hợp lệ), 1 = chỉ lưu khi tất cả dòng hợp lệ'
 WHERE config_key = 'import_strict_mode';
UPDATE system_configs SET description = 'Chỉ gợi ý ưu tiên: chỉ số điện thấp hơn giá trị này thì gợi ý mức Thấp. BQL quyết định ưu tiên cuối.'
 WHERE config_key = 'j16_low_max_kwh';
UPDATE system_configs SET description = 'Chỉ gợi ý ưu tiên: chỉ số điện từ giá trị này trở lên thì gợi ý mức Cao. BQL quyết định ưu tiên cuối.'
 WHERE config_key = 'j16_high_min_kwh';
UPDATE system_configs SET description = 'Chỉ gợi ý ưu tiên: lượng nước thấp hơn giá trị này thì gợi ý mức Thấp. BQL quyết định ưu tiên cuối.'
 WHERE config_key = 'j16_low_max_m3';
UPDATE system_configs SET description = 'Chỉ gợi ý ưu tiên: lượng nước từ giá trị này trở lên thì gợi ý mức Cao. BQL quyết định ưu tiên cuối.'
 WHERE config_key = 'j16_high_min_m3';
