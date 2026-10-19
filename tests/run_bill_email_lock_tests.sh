#!/usr/bin/env bash
# FN-10.03 gửi Gmail phiếu theo kỳ (thủ công, sau khoá kỳ) - DB TẠM cài mới + php -S (mail "log"); KHÔNG gửi Gmail thật.
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
T=energy_dss_bill_email_test; PORT=8088
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
m -e "DROP DATABASE IF EXISTS $T"
TEST_DB_COLLATE=utf8mb4_general_ci bash tests/support/create_test_db.sh "$T" || exit 1   # DB tạm từ database/energy_dss_v2.sql
PHP_CLI_SERVER_WORKERS=2 REVIEW_DB=$T REVIEW_URL=http://127.0.0.1:$PORT REVIEW_MAIL_DRIVER=log "$PHP_BIN" -S 127.0.0.1:$PORT -t public tests/support/review_router.php >/dev/null 2>&1 & srv=$!
sleep 1
"$PHP_BIN" tests/bill_email_lock_test.php http://127.0.0.1:$PORT $T; rc=$?
pkill -P $srv 2>/dev/null; kill $srv 2>/dev/null; wait $srv 2>/dev/null   # PHP_CLI_SERVER_WORKERS: dừng cả worker con (không để mồ côi giữ cổng)
m -e "DROP DATABASE IF EXISTS $T"
exit $rc
