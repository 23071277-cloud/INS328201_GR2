#!/usr/bin/env bash
# UX FN-04.02 - màn Nhập chỉ số dạng worklist, trên DB TẠM cài mới (seed: kỳ 07/2024 Mở, 8 công tơ chờ ghi) + php -S. Không đụng DB thật.
#   bash tests/run_reading_worklist_tests.sh      (Chrome headless cho phần JS / responsive; không có -> SKIPPED)
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
T=energy_dss_worklist_test; PORT=8096
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
m -e "DROP DATABASE IF EXISTS $T"
bash tests/support/create_test_db.sh "$T" || exit 1   # DB tạm từ database/energy_dss_v2.sql
REVIEW_DB=$T REVIEW_URL=http://127.0.0.1:$PORT "$PHP_BIN" -S 127.0.0.1:$PORT -t public tests/support/review_router.php >/dev/null 2>&1 & srv=$!
sleep 1
"$PHP_BIN" tests/reading_worklist_test.php http://127.0.0.1:$PORT $T; rc=$?
kill $srv 2>/dev/null; wait $srv 2>/dev/null
m -e "DROP DATABASE IF EXISTS $T"
exit $rc
