#!/usr/bin/env bash
# STEP 15 - BẰNG CHỨNG thứ tự khoá FN-03 (SQL thuần, 2 phiên mysql song song, SLEEP để xác định thứ tự).
#   Kịch bản "row → series" (code trước STEP 15: findForUpdate(id) rồi lockSeries) và
#   kịch bản "series → row" (sau STEP 15). In số deadlock (ERROR 1213) của từng kịch bản.
#   DB TẠM energy_dss_lockdemo, xoá sau khi chạy. Dùng: bash tests/fn03_lock_order_demo.sh
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
T=energy_dss_lockdemo
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
OUT="$(mktemp -d)"
m -e "DROP DATABASE IF EXISTS $T"
bash tests/support/create_test_db.sh "$T" || exit 1   # DB tạm từ database/energy_dss_v2.sql
# 2 phiên bản tương lai trong cùng chuỗi điện/sinh hoạt + cùng loại phí (đều sửa được: chưa tới ngày hiệu lực)
if [ "${TEST_SCHEMA:-v2}" = "v2" ]; then
  # STEP 4C - schema v2: cùng kịch bản trên pricing_plans, đúng câu khoá của TariffRepository / FixedFeeRepository::lockSeries (v2)
  TT=pricing_plans; FT=pricing_plans
  m $T -e "INSERT INTO pricing_plans (price_type, utility_type, usage_type, effective_date, vat_percent, status) VALUES ('utility','dien','sinh_hoat','2099-01-01',8,'hieu_luc'),('utility','dien','sinh_hoat','2099-06-01',8,'hieu_luc');
           INSERT INTO pricing_plans (price_type, fee_type, flat_unit_price, effective_date, status) VALUES ('fixed_fee','gui_xe_may',100000,'2099-01-01','hieu_luc'),('fixed_fee','gui_xe_may',110000,'2099-06-01','hieu_luc');" || exit 1
  A=$(m -N $T -e "SELECT MIN(id) FROM pricing_plans WHERE price_type='utility' AND effective_date='2099-01-01'"); B=$((A+1))
  FA=$(m -N $T -e "SELECT MIN(id) FROM pricing_plans WHERE price_type='fixed_fee' AND effective_date='2099-01-01'"); FB=$((FA+1))
  TS="SELECT id FROM pricing_plans WHERE price_type='utility' AND approval_status='da_duyet' AND utility_type='dien' AND usage_type='sinh_hoat' ORDER BY effective_date DESC, id DESC FOR UPDATE"
  FS="SELECT id FROM pricing_plans WHERE price_type='fixed_fee' AND fee_type='gui_xe_may' FOR UPDATE"
else
  TT=tariffs; FT=fixed_fee_rates
  m $T -e "INSERT INTO tariffs (utility_type, usage_type, effective_date, vat_percent) VALUES ('dien','sinh_hoat','2099-01-01',8),('dien','sinh_hoat','2099-06-01',8);
           INSERT INTO fixed_fee_rates (fee_type, unit_price, effective_date) VALUES ('gui_xe_may',100000,'2099-01-01'),('gui_xe_may',110000,'2099-06-01');" || exit 1
  A=$(m -N $T -e "SELECT MIN(id) FROM tariffs WHERE effective_date='2099-01-01'"); B=$((A+1))
  FA=$(m -N $T -e "SELECT MIN(id) FROM fixed_fee_rates WHERE effective_date='2099-01-01'"); FB=$((FA+1))
  TS="SELECT id FROM tariffs WHERE utility_type='dien' AND usage_type='sinh_hoat' ORDER BY effective_date DESC, id DESC FOR UPDATE"
  FS="SELECT id FROM fixed_fee_rates WHERE fee_type='gui_xe_may' FOR UPDATE"
fi

# $1 = tên kịch bản, $2/$3 = SQL của phiên 1/2. Trả số phiên bị ERROR 1213 (deadlock).
scenario() {
    m $T -e "SET innodb_lock_wait_timeout=10; $2" >"$OUT/$1.1" 2>&1 &
    m $T -e "SET innodb_lock_wait_timeout=10; $3" >"$OUT/$1.2" 2>&1 &
    wait
    grep -l "1213" "$OUT/$1.1" "$OUT/$1.2" 2>/dev/null | wc -l | tr -d ' '
}
row_first() { echo "BEGIN; SELECT id FROM $1 WHERE id=$2 FOR UPDATE; SELECT SLEEP(1); $3; COMMIT;"; }
series_first() { echo "BEGIN; $3; SELECT id FROM $1 WHERE id=$2 FOR UPDATE; SELECT SLEEP(1); COMMIT;"; }

r1=$(scenario t_old "$(row_first $TT $A "$TS")" "$(row_first $TT $B "$TS")")
r2=$(scenario t_new "$(series_first $TT $A "$TS")" "$(series_first $TT $B "$TS")")
r3=$(scenario t_old_create "$(row_first $TT $B "$TS")" "SELECT SLEEP(0.3); BEGIN; $TS; COMMIT;")
r4=$(scenario t_new_create "$(series_first $TT $B "$TS")" "SELECT SLEEP(0.3); BEGIN; $TS; COMMIT;")
r5=$(scenario f_old "$(row_first $FT $FA "$FS")" "$(row_first $FT $FB "$FS")")
r6=$(scenario f_new "$(series_first $FT $FA "$FS")" "$(series_first $FT $FB "$FS")")
m -e "DROP DATABASE IF EXISTS $T"; rm -rf "$OUT"

echo "tariffs  sửa 2 phiên bản cùng chuỗi : row→series deadlock=$r1 | series→row deadlock=$r2"
echo "tariffs  sửa + tạo cùng chuỗi        : row→series deadlock=$r3 | series→row deadlock=$r4"
echo "fixed_fee sửa 2 đơn giá cùng loại    : row→series deadlock=$r5 | series→row deadlock=$r6"
[ "$r2" = 0 ] && [ "$r4" = 0 ] && [ "$r6" = 0 ]
