#!/usr/bin/env bash
# Dựng DATABASE TẠM cho test từ FILE CSDL DUY NHẤT database/energy_dss_v2.sql (schema 27 bảng + dữ liệu demo seed),
# đúng 2 lệnh cài mới của README:
#   CREATE DATABASE <db> CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;  mysql <db> < database/energy_dss_v2.sql
#   bash tests/support/create_test_db.sh <db_tạm>
# TEST_DB_COLLATE: đổi collation DB tạm (mặc định utf8mb4_general_ci như DB thật).
# Không bao giờ chạm energy_dss / energy_dss_v2 (từ chối tên đó).
# (Trước bản nộp cuối: dựng v1 + seed rồi migrate sang v2 - các file đó đã chuyển ra ~/energy-dss-archive/database-history/;
#  DB dựng từ energy_dss_v2.sql giống hệt DB dựng theo cách cũ: cùng DDL 26 bảng, cùng CHECKSUM dữ liệu, cùng AUTO_INCREMENT.)
set -u
cd "$(dirname "$0")/../.."
T="${1:?Thiếu tên DB tạm}"; shift
case "$T" in energy_dss|energy_dss_v2) echo "create_test_db: từ chối dùng $T làm DB tạm" >&2; exit 2;; esac
[[ "$T" =~ ^[a-z0-9_]+$ ]] || { echo "create_test_db: tên DB không hợp lệ: $T" >&2; exit 2; }
if [ "${TEST_SCHEMA:-v2}" != "v2" ]; then
  echo "create_test_db: TEST_SCHEMA=${TEST_SCHEMA} không còn hỗ trợ - schema v1 đã lưu trữ (~/energy-dss-archive/database-history/)" >&2; exit 2
fi
[ $# -eq 0 ] || { echo "create_test_db: không còn nhận file SQL thêm" >&2; exit 2; }
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/bin/mysql}"
COLLATE="${TEST_DB_COLLATE:-utf8mb4_general_ci}"
m() { "$MYSQL_BIN" -u root --default-character-set=utf8mb4 "$@"; }

m -e "DROP DATABASE IF EXISTS $T" || exit 1
m -e "CREATE DATABASE $T CHARACTER SET utf8mb4 COLLATE $COLLATE" || exit 1
if ! m "$T" < database/energy_dss_v2.sql; then
  echo "create_test_db: nạp database/energy_dss_v2.sql lỗi" >&2; m -e "DROP DATABASE IF EXISTS $T"; exit 1
fi
