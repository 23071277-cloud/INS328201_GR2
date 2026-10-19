#!/usr/bin/env bash
# STEP 14 (+ STEP 15: FN-01.03/06/07, STEP 16: diễn tập demo) - chạy TOÀN BỘ bộ test Core MVP (FN-01 → FN-09) + review cuối. Không đụng dữ liệu thật ngoài các bản ghi E2E tự dọn.
#   bash tests/run_all_tests.sh          (biến môi trường MYSQL_BIN, PHP_BIN để đổi đường dẫn; TEST_SCHEMA=v2 là mặc định)
# Mọi DB tạm dựng từ file CSDL duy nhất database/energy_dss_v2.sql (tests/support/create_test_db.sh).
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
status=0

# STEP 4C: TEST_SCHEMA=v2 -> DB tạm dựng theo schema 27 bảng; các suite "DB thật" chạy trên energy_dss_v2 qua php -S
# (router test: mail log, không gửi Gmail thật). Production (.env / Apache / energy_dss) KHÔNG đổi.
if [ "${TEST_SCHEMA:-v2}" != "v2" ]; then
  echo "TEST_SCHEMA=${TEST_SCHEMA} không còn hỗ trợ: schema v1 + seed + migrate đã lưu trữ (~/energy-dss-archive/database-history/)."; exit 2
fi
if true; then
  REAL_DB="${V2_DB:-energy_dss_v2}"; V2_PORT="${V2_PORT:-8099}"
  [ "$REAL_DB" != "energy_dss" ] || { echo "V2_DB không được là energy_dss"; exit 2; }
  PHP_CLI_SERVER_WORKERS=4 REVIEW_DB="$REAL_DB" REVIEW_URL="http://127.0.0.1:$V2_PORT" REVIEW_MAIL_DRIVER=log "$PHP_BIN" -S 127.0.0.1:$V2_PORT -t public tests/support/review_router.php >/dev/null 2>&1 & V2_SRV=$!
  trap 'pkill -P $V2_SRV 2>/dev/null; kill $V2_SRV 2>/dev/null' EXIT   # dừng cả worker con của php -S
  sleep 1
  export TEST_SCHEMA=v2 DB_DATABASE="$REAL_DB" APP_URL="http://127.0.0.1:$V2_PORT"
  echo "== SCHEMA v2: DB tạm 27 bảng; suite DB thật -> $REAL_DB qua $APP_URL"
fi
run() { echo "== $1"; shift; "$@" 2>&1 | grep -E "/[0-9]+ PASS|^FAIL|  FAIL|service=|fail=" ; [ "${PIPESTATUS[0]}" -eq 0 ] || status=1; }

run "Schema review (DB thật, không ghi dữ liệu lâu dài)" "$PHP_BIN" tests/schema_review_test.php "$REAL_DB"
run "Schema v2 27 bảng (DB nháp, ràng buộc / CHECK / FK)" bash tests/run_v2_scratch_validation.sh
run "Input robustness (FN-01 → FN-04)" "$PHP_BIN" tests/input_robustness_test.php
for n in 1 2 3 4 5 6 7 8 9; do run "FN-0$n" bash tests/run_fn0${n}_tests.sh; done
run "FN-10.03 email (Secondary, không gửi thật)" bash tests/run_fn10_tests.sh
run "UX công tơ: auto-suggest + import theo mã công tơ" bash tests/run_meter_ux_tests.sh
run "UX tòa nhà: auto-suggest mã từ tên" "$PHP_BIN" tests/building_suggest_ui_test.php
run "UX khu vực: auto-suggest mã từ tòa nhà + tên" "$PHP_BIN" tests/zone_suggest_ui_test.php
run "UX công tơ: điều hướng về căn hộ" "$PHP_BIN" tests/meter_apartment_nav_test.php
run "UX nhập chỉ số: worklist (DB tạm, php -S)" bash tests/run_reading_worklist_tests.sh
run "J-16 BA Proposal: căn khai báo không phát sinh có CL > 0 (DB tạm)" bash tests/run_j16_tests.sh
run "Dashboard sau J-16: tiện ích NULL / thiếu trường (DB tạm)" bash tests/run_dashboard_j16_tests.sh
run "UX live (không tải lại trang) + badge việc cần xử lý (DB tạm, Chrome)" bash tests/run_live_ux_tests.sh
run "UI trang đăng nhập (DB tạm, Chrome)" bash tests/run_login_ui_tests.sh
run "FN-10.03 gửi Gmail phiếu theo kỳ thủ công (DB tạm)" bash tests/run_bill_email_lock_tests.sh
run "UI chi tiết kỳ ghi chỉ số: danh sách công tơ đang hoạt động (DB tạm, Chrome)" bash tests/run_period_detail_ui_tests.sh
run "UI sidebar thu gọn / mở rộng cho BQL, Kế toán, Kỹ thuật (DB tạm, Chrome)" bash tests/run_sidebar_ui_tests.sh
run "Song ngữ vi / en: nền tảng i18n + topbar / sidebar, 3 vai trò (DB tạm, php -S)" bash tests/run_i18n_tests.sh
run "Thanh toán: đối soát + Mock Bank + email xác nhận + dashboard (DB tạm, php -S)" bash tests/run_payment_tests.sh

T=energy_dss_review_test
m -e "DROP DATABASE IF EXISTS $T"
bash tests/support/create_test_db.sh "$T"   # DB tạm từ database/energy_dss_v2.sql
run "Toàn vẹn DB (DB tạm)" "$PHP_BIN" tests/core_db_integrity_test.php $T
m -e "DROP DATABASE IF EXISTS $T"
run "Bảo mật toàn hệ thống (Apache)" "$PHP_BIN" tests/core_security_test.php
run "Core E2E xuyên hệ thống (Apache + DB thật, fixture riêng)" "$PHP_BIN" tests/core_e2e_test.php
run "Core E2E xuyên hệ thống (DB tạm cài mới - độc lập dữ liệu thật, gồm khoá kỳ)" bash tests/run_isolated.sh tests/core_e2e_test.php
run "FN-05 HTTP (DB tạm cài mới - khoá / mở lại kỳ độc lập dữ liệu thật)" bash tests/run_isolated.sh tests/fn05_http_test.php
run "Cài mới từ database/energy_dss_v2.sql (DB tạm, php -S)" bash tests/run_install_upgrade_test.sh
run "Diễn tập kịch bản demo (DB tạm cài mới, php -S)" bash tests/run_demo_rehearsal.sh

echo "== Lint"
n=0; f=0
for x in $(find app config routes resources tests tools bootstrap.php public lib -name "*.php"); do n=$((n+1)); "$PHP_BIN" -l "$x" >/dev/null 2>&1 || { f=$((f+1)); echo "LINT FAIL $x"; }; done
echo "lint $n file, $f lỗi"; [ $f -eq 0 ] || status=1
echo "TỔNG: $([ $status -eq 0 ] && echo 'PASS' || echo 'CÓ LỖI')"
exit $status
