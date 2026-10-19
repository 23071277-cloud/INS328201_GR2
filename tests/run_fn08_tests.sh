#!/usr/bin/env bash
# STEP 12 - chạy toàn bộ test FN-08.
#   1) Service test trên DB TẠM energy_dss_fn08_test (tạo từ database/energy_dss_v2.sql, xoá sau khi chạy)
#   2) HTTP test qua Apache XAMPP thật (APP_URL trong .env) trên DB energy_dss - tự dọn dữ liệu E2E
# Dùng:  bash tests/run_fn08_tests.sh        (biến môi trường MYSQL_BIN, PHP_BIN để đổi đường dẫn)
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
TEST_DB=energy_dss_fn08_test
mysql_() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }

echo "== Tạo DB tạm $TEST_DB"
mysql_ -e "DROP DATABASE IF EXISTS $TEST_DB"
bash tests/support/create_test_db.sh "$TEST_DB" || exit 1   # DB tạm từ database/energy_dss_v2.sql

echo "== Service test"
"$PHP_BIN" tests/fn08_service_test.php "$TEST_DB"; svc=$?
mysql_ -e "DROP DATABASE IF EXISTS $TEST_DB"

echo "== HTTP test (Apache)"
"$PHP_BIN" tests/fn08_http_test.php; http=$?

echo "service=$svc http=$http"
[ $svc -eq 0 ] && [ $http -eq 0 ]
