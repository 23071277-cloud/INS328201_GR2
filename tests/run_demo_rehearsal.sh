#!/usr/bin/env bash
# STEP 16 - diễn tập kịch bản demo (docs/DEMO_SCRIPT.md) trên DB TẠM cài mới + php -S. Không đụng DB thật / Apache.
#   bash tests/run_demo_rehearsal.sh        (biến môi trường MYSQL_BIN, PHP_BIN để đổi đường dẫn)
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
T=energy_dss_demo_rehearsal; PORT=8097
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
m -e "DROP DATABASE IF EXISTS $T"
bash tests/support/create_test_db.sh "$T" || exit 1   # DB tạm từ database/energy_dss_v2.sql
REVIEW_DB=$T REVIEW_URL=http://127.0.0.1:$PORT "$PHP_BIN" -S 127.0.0.1:$PORT tests/support/review_router.php >/dev/null 2>&1 & srv=$!
sleep 1
"$PHP_BIN" tests/demo_rehearsal_test.php http://127.0.0.1:$PORT $T; rc=$?
kill $srv 2>/dev/null; wait $srv 2>/dev/null
m -e "DROP DATABASE IF EXISTS $T"
exit $rc
