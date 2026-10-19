#!/usr/bin/env bash
# Song ngữ giao diện vi / en: nền tảng i18n + topbar / sidebar / nhãn dùng chung, cả 3 vai trò - DB TẠM + php -S, Chrome headless.
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
T=energy_dss_i18n_test; PORT=8081
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
m -e "DROP DATABASE IF EXISTS $T"
bash tests/support/create_test_db.sh "$T" || exit 1   # DB tạm từ database/energy_dss_v2.sql
PHP_CLI_SERVER_WORKERS=4 REVIEW_DB=$T REVIEW_URL=http://127.0.0.1:$PORT REVIEW_MAIL_DRIVER=log "$PHP_BIN" -S 127.0.0.1:$PORT -t public tests/support/review_router.php >/dev/null 2>&1 & srv=$!
sleep 1
"$PHP_BIN" tests/i18n_test.php http://127.0.0.1:$PORT $T; rc=$?
pkill -P $srv 2>/dev/null; kill $srv 2>/dev/null; wait $srv 2>/dev/null   # PHP_CLI_SERVER_WORKERS: dừng cả worker con
m -e "DROP DATABASE IF EXISTS $T"
exit $rc
