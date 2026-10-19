#!/usr/bin/env bash
# BUGFIX dashboard 500 sau J-16 - DB TẠM cài mới + php -S (không đụng DB thật, mail driver log).
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
T=energy_dss_dash_j16_test; PORT=8093
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
m -e "DROP DATABASE IF EXISTS $T"
bash tests/support/create_test_db.sh "$T" || exit 1   # DB tạm từ database/energy_dss_v2.sql
REVIEW_DB=$T REVIEW_URL=http://127.0.0.1:$PORT REVIEW_MAIL_DRIVER=log "$PHP_BIN" -S 127.0.0.1:$PORT -t public tests/support/review_router.php >/dev/null 2>&1 & srv=$!
sleep 1
"$PHP_BIN" tests/dashboard_j16_test.php http://127.0.0.1:$PORT $T; rc=$?
kill $srv 2>/dev/null; wait $srv 2>/dev/null
m -e "DROP DATABASE IF EXISTS $T"
exit $rc
