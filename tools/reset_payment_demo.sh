#!/usr/bin/env bash
# Reset demo thanh toán trước buổi trình bày (Mock Bank local). CHỈ xoá:
#   - payment_transactions nguồn mock_bank (giao dịch demo)
#   - email_notifications loại payment_confirmation của các bản tính đã nhận giao dịch demo đó
# KHÔNG đụng apartment_charges / chỉ số / kỳ tính phí / dữ liệu nghiệp vụ khác (kiểm tra bằng CHECKSUM trước-sau). Nhật ký audit giữ nguyên.
# Trạng thái thanh toán được TÍNH (không lưu) nên các phiếu tự trở về "Chưa thanh toán".
# Các dòng bị xoá được lưu trước vào ~/energy-dss-archive/payment-demo-resets/ (không xoá vĩnh viễn).
#   bash tools/reset_payment_demo.sh            (mặc định DB energy_dss_v2)
set -euo pipefail
cd "$(dirname "$0")/.."
DB="${1:-energy_dss_v2}"
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-/Applications/XAMPP/bin/mysqldump}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
[[ "$DB" =~ ^[a-z0-9_]+$ ]] || { echo "Tên DB không hợp lệ: $DB" >&2; exit 2; }
[ "$DB" != "energy_dss" ] || { echo "Từ chối: energy_dss là bản rollback, không reset." >&2; exit 2; }
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 -N "$DB" "$@"; }

EMAIL_WHERE="notification_type = 'payment_confirmation' AND apartment_charge_id IN (SELECT apartment_charge_id FROM payment_transactions WHERE source = 'mock_bank' AND apartment_charge_id IS NOT NULL)"
BUSINESS="apartment_charges, billing_periods, meter_readings, apartments, residents, alerts"
sums() { m -e "CHECKSUM TABLE $BUSINESS" | awk '{print $1, $2}'; }

txn=$(m -e "SELECT COUNT(*) FROM payment_transactions WHERE source = 'mock_bank'")
mail=$(m -e "SELECT COUNT(*) FROM email_notifications WHERE $EMAIL_WHERE")
echo "DB $DB: giao dịch demo = $txn, email xác nhận thanh toán demo = $mail"
if [ "$txn" = "0" ] && [ "$mail" = "0" ]; then
  echo "Không có gì để reset."; exit 0
fi

OUT=~/energy-dss-archive/payment-demo-resets; mkdir -p "$OUT"
F="$OUT/${DB}_$(date +%Y%m%d_%H%M%S).sql"
"$MYSQLDUMP_BIN" -u root --single-transaction --no-create-info --skip-triggers --default-character-set=utf8mb4 "$DB" email_notifications --where="$EMAIL_WHERE" > "$F"
"$MYSQLDUMP_BIN" -u root --single-transaction --no-create-info --skip-triggers --default-character-set=utf8mb4 "$DB" payment_transactions --where="source = 'mock_bank'" >> "$F"
echo "Đã lưu bản sao các dòng sẽ xoá: $F"

before=$(sums)
m -e "START TRANSACTION;
DELETE FROM email_notifications WHERE $EMAIL_WHERE;
DELETE FROM payment_transactions WHERE source = 'mock_bank';
COMMIT;"
after=$(sums)
[ "$before" = "$after" ] || { echo "LỖI: dữ liệu nghiệp vụ thay đổi - khôi phục từ backup!" >&2; exit 1; }

echo "Sau reset: giao dịch demo = $(m -e "SELECT COUNT(*) FROM payment_transactions WHERE source = 'mock_bank'"), email xác nhận demo = $(m -e "SELECT COUNT(*) FROM email_notifications WHERE notification_type = 'payment_confirmation'")"
echo "Dữ liệu nghiệp vụ ($BUSINESS): không đổi (checksum trước = sau)"
DB_DATABASE="$DB" "$PHP_BIN" -r '
require "bootstrap.php";
$s = new App\Services\PaymentStatusService(); $n = [];
foreach ($s->periods() as $p) { foreach ($s->periodSummary((int) $p["id"])["rows"] as $r) { $n[$r["status"]] = ($n[$r["status"]] ?? 0) + 1; } }
ksort($n); echo "Trạng thái thanh toán hiện tại: ", json_encode($n), PHP_EOL;'
echo "RESET XONG"
