#!/usr/bin/env bash
# [BA Proposal / Assumption J-16] test luồng "Phát sinh tiêu thụ tại căn khai báo không sử dụng" - KHÔNG đụng DB thật, KHÔNG gửi email thật.
#   1) Service test trên DB TẠM energy_dss_j16_test (mail driver "array" = fake transport, giả lập lỗi bằng EmailService::fail)
#   2) HTTP E2E trên DB TẠM energy_dss_j16_http + php -S (router ép mail driver "log", bỏ qua MAIL_DRIVER=smtp của .env)
#   Gmail thật CHỈ qua smoke test thủ công: tests/support/gmail_smoke.php (xem docs, STEP 25).
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
fresh() {
  m -e "DROP DATABASE IF EXISTS $1"
  bash tests/support/create_test_db.sh "$1" || exit 1   # DB tạm từ database/energy_dss_v2.sql
}
SVC=energy_dss_j16_test; WEB=energy_dss_j16_http; PORT=8094
echo "== Service test (DB tạm)"
fresh $SVC
"$PHP_BIN" tests/j16_service_test.php $SVC; svc=$?
m -e "DROP DATABASE IF EXISTS $SVC"
echo "== HTTP E2E (DB tạm, php -S)"
fresh $WEB
REVIEW_DB=$WEB REVIEW_URL=http://127.0.0.1:$PORT REVIEW_MAIL_DRIVER=log "$PHP_BIN" -S 127.0.0.1:$PORT -t public tests/support/review_router.php >/dev/null 2>&1 & srv=$!
sleep 1
"$PHP_BIN" tests/j16_http_test.php http://127.0.0.1:$PORT $WEB; http=$?
kill $srv 2>/dev/null; wait $srv 2>/dev/null
m -e "DROP DATABASE IF EXISTS $WEB"
echo "service=$svc http=$http"
[ $svc -eq 0 ] && [ $http -eq 0 ]
