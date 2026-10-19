#!/usr/bin/env bash
# STEP 25 - test FN-10.03 gửi thông báo phí qua email (KHÔNG gửi email thật trong test tự động).
#   1) Service test trên DB TẠM energy_dss_fn10_test (driver array + SMTP tới cổng đóng 127.0.0.1:1), xoá sau khi chạy
#   2) HTTP test qua Apache thật (APP_URL) - bỏ qua bước gửi nếu .env đang MAIL_DRIVER=smtp
#   3) Gmail thật: chỉ khi .env đủ MAIL_DRIVER=smtp + MAIL_USERNAME + MAIL_PASSWORD + MAIL_TEST_RECIPIENT; thiếu = SKIPPED (không lỗi)
#      Bật bằng: RUN_GMAIL_SMOKE=1 bash tests/run_fn10_tests.sh
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
TEST_DB=energy_dss_fn10_test
mysql_() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }

echo "== Tạo DB tạm $TEST_DB"
mysql_ -e "DROP DATABASE IF EXISTS $TEST_DB"
bash tests/support/create_test_db.sh "$TEST_DB" || exit 1   # DB tạm từ database/energy_dss_v2.sql

echo "== Service test"
"$PHP_BIN" tests/fn10_email_service_test.php "$TEST_DB"; svc=$?
mysql_ -e "DROP DATABASE IF EXISTS $TEST_DB"

echo "== HTTP test (Apache)"
"$PHP_BIN" tests/fn10_email_http_test.php; http=$?

smoke=0
if [ "${RUN_GMAIL_SMOKE:-0}" = "1" ]; then
  echo "== Gmail thật (tùy chọn)"
  "$PHP_BIN" tests/support/gmail_smoke.php; smoke=$?
else
  echo "== Gmail thật: không chạy (đặt RUN_GMAIL_SMOKE=1 để chạy)"
fi

echo "service=$svc http=$http smoke=$smoke"
[ $svc -eq 0 ] && [ $http -eq 0 ] && [ $smoke -eq 0 ]
