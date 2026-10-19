#!/usr/bin/env bash
# Test CÀI MỚI từ file CSDL duy nhất database/energy_dss_v2.sql (không đụng energy_dss / energy_dss_v2).
#   1) Đúng 2 lệnh cài mới của README trên DB tạm review_fresh: CREATE DATABASE ... utf8mb4_general_ci; mysql review_fresh < energy_dss_v2.sql
#      -> 27 bảng, 3 tài khoản demo đúng vai trò, collation đúng; file không có DROP / CREATE DATABASE / USE.
#   2) Nạp lại vào DB đã có dữ liệu -> phải LỖI (CREATE TABLE không IF NOT EXISTS) và dữ liệu KHÔNG đổi (không ghi đè).
#   3) App boot trên DB vừa cài (php -S + router test, mail log) -> smoke HTTP (đăng nhập 3 vai trò, 22 màn hình, tạo kỳ, nhập / duyệt chỉ số, tính phí).
# (Các test nâng cấp schema v1 cũ -> patch 001..005 -> migrate v1 -> v2 đã lưu trữ cùng các file v1 ở ~/energy-dss-archive/database-history/.)
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
F=database/energy_dss_v2.sql; FRESH=review_fresh; PORT=8098; fail=0
smoke() {
  REVIEW_DB=$1 REVIEW_URL=http://127.0.0.1:$PORT "$PHP_BIN" -S 127.0.0.1:$PORT tests/support/review_router.php >/dev/null 2>&1 & srv=$!
  sleep 1; "$PHP_BIN" tests/support/install_smoke.php http://127.0.0.1:$PORT "$1" || fail=1; kill $srv 2>/dev/null; wait $srv 2>/dev/null
}
checksum() { T=$(m -N -e "SELECT GROUP_CONCAT(CONCAT('$1.', table_name) ORDER BY table_name) FROM information_schema.tables WHERE table_schema='$1'"); m -N -e "CHECKSUM TABLE ${T//,/, }"; }

echo "== 1) Cài mới: 2 lệnh của README"
if grep -qiE '^[[:space:]]*(DROP|CREATE DATABASE|USE)[[:space:]]' "$F"; then echo "FAIL $F có DROP / CREATE DATABASE / USE"; fail=1; else echo "$F không có DROP / CREATE DATABASE / USE"; fi
m -e "DROP DATABASE IF EXISTS $FRESH"
m -e "CREATE DATABASE $FRESH CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" || fail=1
m "$FRESH" < "$F" || { echo "FAIL nạp $F"; fail=1; }
n=$(m -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$FRESH'")
[ "$n" = "27" ] && echo "bảng: 27" || { echo "FAIL bảng: $n (cần 27)"; fail=1; }
acc=$(m -N "$FRESH" -e "SELECT GROUP_CONCAT(CONCAT(username,':',role,':',status) ORDER BY id) FROM users")
[ "$acc" = "bql01:bql:hoat_dong,kythuat01:ky_thuat:hoat_dong,ketoan01:ke_toan:hoat_dong" ] && echo "tài khoản demo: $acc" || { echo "FAIL tài khoản: $acc"; fail=1; }
col=$(m -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$FRESH' AND table_collation <> 'utf8mb4_general_ci'")
[ "$col" = "0" ] && echo "collation 27 bảng: utf8mb4_general_ci" || { echo "FAIL $col bảng khác collation"; fail=1; }

echo "== 2) Nạp lại vào DB đã có dữ liệu: phải lỗi, không ghi đè"
before=$(checksum $FRESH)
if m "$FRESH" < "$F" 2>/dev/null; then echo "FAIL nạp lại KHÔNG báo lỗi"; fail=1; else echo "nạp lại bị từ chối (đúng)"; fi
[ "$(checksum $FRESH)" = "$before" ] && echo "dữ liệu 27 bảng không đổi sau lần nạp lại" || { echo "FAIL dữ liệu bị đổi"; fail=1; }

echo "== 3) App trên DB vừa cài"
smoke $FRESH
m -e "DROP DATABASE IF EXISTS $FRESH"
echo "install fail=$fail"
exit $fail
