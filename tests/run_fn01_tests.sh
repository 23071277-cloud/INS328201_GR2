#!/usr/bin/env bash
# STEP 15 - chạy toàn bộ test FN-01.03 / 01.06 / 01.07 + thu hồi phiên.
#   1) Service test trên DB TẠM energy_dss_fn01_test (tạo từ database/energy_dss_v2.sql, xoá sau khi chạy)
#   2) HTTP test qua Apache XAMPP thật (APP_URL trong .env) trên DB energy_dss - tự dọn dữ liệu E2E, khôi phục tham số
#   3) Bằng chứng thứ tự khoá FN-03 (SQL thuần, DB tạm)
# Dùng:  bash tests/run_fn01_tests.sh        (biến môi trường MYSQL_BIN, PHP_BIN để đổi đường dẫn)
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
TEST_DB=energy_dss_fn01_test
mysql_() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }

echo "== Tạo DB tạm $TEST_DB"
mysql_ -e "DROP DATABASE IF EXISTS $TEST_DB"
bash tests/support/create_test_db.sh "$TEST_DB" || exit 1   # DB tạm từ database/energy_dss_v2.sql

echo "== Service test"
"$PHP_BIN" tests/fn01_service_test.php "$TEST_DB"; svc=$?
mysql_ -e "DROP DATABASE IF EXISTS $TEST_DB"

echo "== HTTP test (Apache)"
"$PHP_BIN" tests/fn01_http_test.php; http=$?

echo "== FN-03 lock order (SQL thuần)"
MYSQL_BIN="$MYSQL_BIN" bash tests/fn03_lock_order_demo.sh; lock=$?

echo "service=$svc http=$http lock=$lock"
[ $svc -eq 0 ] && [ $http -eq 0 ] && [ $lock -eq 0 ]
