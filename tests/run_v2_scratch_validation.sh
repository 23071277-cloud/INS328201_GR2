#!/usr/bin/env bash
# Kiểm tra ràng buộc schema 27 bảng: apply PHẦN SCHEMA (trước khối "DỮ LIỆU DEMO") của database/energy_dss_v2.sql lên
# DATABASE NHÁP energy_dss_v2_scratch (rỗng), chạy kiểm tra, rồi DROP DB nháp. KHÔNG đụng energy_dss / energy_dss_v2, KHÔNG sửa file SQL.
set -u
cd "$(dirname "$0")/.."
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/bin/php}"
T=energy_dss_v2_scratch
SRC=database/energy_dss_v2.sql
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }
cleanup() { m -e "DROP DATABASE IF EXISTS $T"; }
trap cleanup EXIT

# Luồng apply: CREATE DATABASE + USE DB nháp, rồi phần schema của file (cắt từ dòng "-- DỮ LIỆU DEMO");
# chặn nếu còn lệnh DROP / tham chiếu tới DB thật
grep -q '^-- DỮ LIỆU DEMO' "$SRC" || { echo "Không thấy khối DỮ LIỆU DEMO trong $SRC - dừng." >&2; exit 2; }
APPLY=$(printf 'CREATE DATABASE %s\n  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;\nUSE %s;\n' "$T" "$T"; sed '/^-- DỮ LIỆU DEMO/,$d' "$SRC")
if printf '%s' "$APPLY" | grep -qiE '^[[:space:]]*DROP[[:space:]]|^[[:space:]]*USE[[:space:]]+energy_dss[[:space:]]*;|^CREATE DATABASE energy_dss$|^USE energy_dss_v2;|^CREATE DATABASE energy_dss_v2$'; then
  echo "Schema đích có DROP / tham chiếu energy_dss thật - dừng." >&2; exit 2
fi
if [ "$(printf '%s\n' "$APPLY" | grep -cE "^(CREATE DATABASE $T|USE $T;)\$")" != "2" ] || [ "$(printf '%s\n' "$APPLY" | grep -ciE '^[[:space:]]*USE[[:space:]]')" != "1" ]; then
  echo "Không đổi được tên DB sang $T - dừng." >&2; exit 2
fi
m -e "DROP DATABASE IF EXISTS $T"
echo "== apply $SRC -> $T"
ERR=$(mktemp)
if ! printf '%s\n' "$APPLY" | m --show-warnings 2> "$ERR"; then
  echo "APPLY FAIL:"; cat "$ERR"; rm -f "$ERR"; exit 1
fi
echo "Cảnh báo khi apply: $(grep -c . "$ERR")"; cat "$ERR"; rm -f "$ERR"
echo "APPLY OK ($(m -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$T'") bảng)"
"$PHP_BIN" tests/v2_scratch_validation_test.php "$T"; rc=$?

# P-4A-1: cài lại với FOREIGN_KEY_CHECKS = 1 (bỏ dòng SET ... = 0 trong luồng đọc) - phải đủ 27 bảng, không lỗi thứ tự FK
m -e "DROP DATABASE IF EXISTS $T"
FKON=$(printf '%s\n' "$APPLY" | sed '/^SET FOREIGN_KEY_CHECKS = 0;/d')
if printf '%s\n' "$FKON" | grep -q 'FOREIGN_KEY_CHECKS = 0'; then echo 'FK-ON INSTALL: không bỏ được SET FOREIGN_KEY_CHECKS = 0' >&2; exit 1; fi
ERR=$(mktemp)
if printf '%s\n' "$FKON" | m 2> "$ERR" && [ "$(m -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$T'")" = "27" ]; then
  echo "FK-ON INSTALL: PASS (27 bảng, FOREIGN_KEY_CHECKS = 1)"
else
  echo "FK-ON INSTALL: FAIL"; cat "$ERR"; rc=1
fi
rm -f "$ERR"
exit $rc
