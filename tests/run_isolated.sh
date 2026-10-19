#!/usr/bin/env bash
# Chạy 1 test HTTP (vốn chạy trên Apache + DB thật) trên DB TẠM cài mới (database/energy_dss_v2.sql) + php -S - độc lập dữ liệu người dùng.
#   bash tests/run_isolated.sh tests/core_e2e_test.php
# Test phải nạp tests/support/real_db_fixture.php (đọc E2E_DB / E2E_BASE). DB tạm cùng collation với DB thật (general_ci).
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
T=energy_dss_isolated_$(basename "$1" .php); PORT=8090
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
m -e "DROP DATABASE IF EXISTS $T"
TEST_DB_COLLATE=utf8mb4_general_ci bash tests/support/create_test_db.sh "$T" || exit 1   # DB tạm từ database/energy_dss_v2.sql
REVIEW_DB=$T REVIEW_URL=http://127.0.0.1:$PORT "$PHP_BIN" -S 127.0.0.1:$PORT -t public tests/support/review_router.php >/dev/null 2>&1 & srv=$!
sleep 1
E2E_DB=$T E2E_BASE=http://127.0.0.1:$PORT "$PHP_BIN" "$1"; rc=$?
kill $srv 2>/dev/null; wait $srv 2>/dev/null
m -e "DROP DATABASE IF EXISTS $T"
exit $rc
