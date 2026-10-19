#!/usr/bin/env bash
# Payment module: service test + HTTP test trên DB TẠM (dựng từ database/energy_dss_v2.sql). KHÔNG đụng DB thật, KHÔNG gửi email thật.
#   Energy DSS server A (8086): callback bật (MOCK_BANK_ENABLED=1 + secret CHỈ dùng cho test); server B (8085): tắt (như production). Router test ép mail "log".
#   Mock Bank ĐỘC LẬP (tools/mock-bank): 8083 gửi callback tới A; 8082 trỏ callback ra ngoài máy local (phải từ chối gửi).
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
T=energy_dss_payment_test; H=energy_dss_payment_http; SECRET="test-only-mock-bank-secret-$$"

m -e "DROP DATABASE IF EXISTS $T"
bash tests/support/create_test_db.sh "$T" || exit 1   # DB tạm từ database/energy_dss_v2.sql
"$PHP_BIN" tests/payment_service_test.php "$T"; svc=$?
m -e "DROP DATABASE IF EXISTS $T"

m -e "DROP DATABASE IF EXISTS $H"
bash tests/support/create_test_db.sh "$H" || exit 1
MOCK_BANK_ENABLED=1 MOCK_BANK_SECRET="$SECRET" PHP_CLI_SERVER_WORKERS=4 REVIEW_DB=$H REVIEW_URL=http://127.0.0.1:8086 \
  "$PHP_BIN" -S 127.0.0.1:8086 -t public tests/support/review_router.php >/dev/null 2>&1 & srvA=$!
env -u MOCK_BANK_ENABLED -u MOCK_BANK_SECRET PHP_CLI_SERVER_WORKERS=2 REVIEW_DB=$H REVIEW_URL=http://127.0.0.1:8085 \
  "$PHP_BIN" -S 127.0.0.1:8085 -t public tests/support/review_router.php >/dev/null 2>&1 & srvB=$!
MOCK_BANK_SECRET="$SECRET" MOCK_BANK_CALLBACK_URL=http://127.0.0.1:8086/payments/callback "$PHP_BIN" -S 127.0.0.1:8083 -t tools/mock-bank >/dev/null 2>&1 & toolA=$!
MOCK_BANK_SECRET="$SECRET" MOCK_BANK_CALLBACK_URL=http://example.com/payments/callback "$PHP_BIN" -S 127.0.0.1:8082 -t tools/mock-bank >/dev/null 2>&1 & toolBad=$!
sleep 1
"$PHP_BIN" tests/payment_http_test.php http://127.0.0.1:8086 http://127.0.0.1:8085 "$H" "$SECRET" http://127.0.0.1:8083 http://127.0.0.1:8082; http=$?
for s in $srvA $srvB $toolA $toolBad; do pkill -P $s 2>/dev/null; kill $s 2>/dev/null; wait $s 2>/dev/null; done   # dừng cả worker con của php -S
m -e "DROP DATABASE IF EXISTS $H"

echo "service=$svc http=$http"
[ $svc -eq 0 ] && [ $http -eq 0 ]
