#!/usr/bin/env bash
# UX "Thêm công tơ" (auto-suggest Mã công tơ / Nhãn) + import FN-04.03 nhận diện bằng meter_code (tương thích file cũ).
#   1) Service test import trên DB TẠM energy_dss_meterux_test (xoá sau khi chạy)
#   2) UI (Chrome headless nếu có - không có thì SKIPPED) + backend qua Apache thật
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
TEST_DB=energy_dss_meterux_test
mysql_() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }

mysql_ -e "DROP DATABASE IF EXISTS $TEST_DB"
bash tests/support/create_test_db.sh "$TEST_DB" || exit 1   # DB tạm từ database/energy_dss_v2.sql
echo "== Import theo mã công tơ (service, DB tạm)"
"$PHP_BIN" tests/meter_ux_import_test.php "$TEST_DB"; svc=$?
mysql_ -e "DROP DATABASE IF EXISTS $TEST_DB"
echo "== Màn Thêm công tơ (JS trong Chrome headless + backend Apache)"
"$PHP_BIN" tests/meter_suggest_ui_test.php; ui=$?
echo "service=$svc ui=$ui"
[ $svc -eq 0 ] && [ $ui -eq 0 ]
