# Authorized deployment command record — 13 October 2026

Working directory: `C:\xampp\htdocs\energy-dss\energy-dss`.
Migration SHA-256: `75b185557656242c37d21d9c522f575dadba8a30e8b87afe9a2f511c87511ede`.
Backup SHA-256: `0da414814aa0891d940ead27740e6566d28fb37506cfa385dd298596ba59bec7`.
Credentials were never printed. No migration SQL was changed.

## Read-only prerequisites

```powershell
Get-FileHash -LiteralPath database/migrations/20261013_billing_reconciliation.sql -Algorithm SHA256
Get-FileHash -LiteralPath C:\EnergyDSS_Backups\energy_dss_before_migration.sql -Algorithm SHA256
Get-Content storage/restore-rehearsal-20261013.json -TotalCount 55
& C:\xampp\mysql\bin\mysql.exe -u root energy_dss -e "SELECT DATABASE() target; SHOW FULL PROCESSLIST; SELECT @@global.read_only read_only; SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='energy_dss' ORDER BY table_name; SELECT TRIGGER_NAME FROM information_schema.triggers WHERE trigger_schema='energy_dss';"
Get-Process httpd,php -ErrorAction SilentlyContinue | Select-Object Id,ProcessName,Path
Get-CimInstance Win32_Process | Where-Object { $_.Name -in @('php.exe','httpd.exe') } | Select-Object ProcessId,ParentProcessId,ExecutablePath,CommandLine
& C:\xampp\php\php.exe tools/deploy_billing_reconciliation_20261013.php --preflight
```

Preflight additionally executes SELECT/SHOW metadata, table data, object definitions and
foreign-key integrity queries through `inventory`, `objects` and `foreignKeys` in the
restore-rehearsal helper. Every original table matched the verified backup exactly.

## Application pause

```powershell
$servers = @(Get-CimInstance Win32_Process | Where-Object { ($_.Name -eq 'httpd.exe' -and $_.ExecutablePath -ieq 'C:\xampp\apache\bin\httpd.exe') -or ($_.Name -eq 'php.exe' -and $_.CommandLine -match '127\.0\.0\.1:8099') })
if ($servers.Count -ne 3) { throw 'Unexpected application process inventory; stopping deployment' }
$servers | Select-Object ProcessId,ParentProcessId,ExecutablePath,CommandLine | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath storage/billing-deployment-server-state.json -Encoding UTF8
foreach ($server in $servers) { Stop-Process -Id $server.ProcessId -Force -ErrorAction Stop }
$remaining = @(Get-CimInstance Win32_Process | Where-Object { $_.Name -in @('httpd.exe','php.exe','mysql.exe','mysqldump.exe') })
if ($remaining.Count -gt 0) { throw 'Application or database client processes remain; stopping deployment' }
@{paused=$true; stopped_process_ids=@($servers.ProcessId); application_ports=@(80,443,8099); reason='Authorized migration maintenance';} | ConvertTo-Json | Set-Content -LiteralPath storage/billing-deployment-pause.json -Encoding ASCII
```

Stopped Apache PIDs 25380/22580 and PHP 8099 PID 5928. No application or database
client processes remained. Database inspection found only the deployment session
and MariaDB internal system threads; no other administrative session/concurrent DDL.

## Apply once

```powershell
& C:\xampp\php\php.exe tools/deploy_billing_reconciliation_20261013.php --apply
```

The script checked target `SELECT DATABASE()='energy_dss'`, obtained
`GET_LOCK('energy_dss_billing_migration_20261013',0)`, recorded the original global
read-only value, executed `SET GLOBAL read_only=ON`, rechecked all data/schema and
hashes, and checked for other database sessions before EACH DDL statement.
The root deployment account can execute DDL under this gate; other administrative
connections were excluded by inspection. Exactly nine reviewed CREATE statements
executed sequentially through PDO. No --force, retries, transaction rollback claim,
DROP, TRUNCATE, data import, UPDATE/INSERT of business records, or migration rerun.

The full nine SQL statements, all verification results, and the restoration command
`SET GLOBAL read_only=0` are recorded verbatim in `billing-deployment-result.json`.
The coordination lock was released with
`RELEASE_LOCK('energy_dss_billing_migration_20261013')` after verification.
All post-migration table/trigger/constraint/data checks were SELECT/SHOW only.

## Restore application servers

```powershell
$apache = Start-Process -FilePath C:\xampp\apache\bin\httpd.exe -ArgumentList @('-d','C:/xampp/apache') -WorkingDirectory C:\xampp\apache\bin -WindowStyle Hidden -PassThru
$php = Start-Process -FilePath C:\xampp\php\php.exe -ArgumentList @('-S','127.0.0.1:8099','-t','public','public/index.php') -WorkingDirectory C:\xampp\htdocs\energy-dss\energy-dss -WindowStyle Hidden -RedirectStandardOutput C:\xampp\htdocs\energy-dss\energy-dss\storage\logs\php-8099-deployment.stdout.log -RedirectStandardError C:\xampp\htdocs\energy-dss\energy-dss\storage\logs\php-8099-deployment.stderr.log -PassThru
@{paused=$false; resumed_process_ids=@($apache.Id,$php.Id); migration_verified=$true;} | ConvertTo-Json | Set-Content -LiteralPath storage/billing-deployment-pause.json -Encoding ASCII
```

Apache resumed PID 29100; PHP 8099 resumed PID 28404. Maintenance gate removed.

## Read-only post-deployment checks

```powershell
curl.exe -s -o NUL -w "PHP 8099 login HTTP %{http_code}\n" http://127.0.0.1:8099/login
curl.exe -s -o NUL -w "Apache login HTTP %{http_code}\n" http://localhost/energy-dss/energy-dss/public/login
& C:\xampp\mysql\bin\mysql.exe -u root energy_dss -e "SELECT @@global.read_only read_only_restored; SELECT 'requests' object,COUNT(*) rows_count FROM billing_adjustment_requests UNION ALL SELECT 'reconciliations',COUNT(*) FROM billing_reconciliations UNION ALL SELECT 'debits',COUNT(*) FROM supplementary_debits; SELECT COUNT(*) new_foreign_keys FROM information_schema.key_column_usage WHERE table_schema='energy_dss' AND table_name IN ('billing_adjustment_requests','billing_reconciliations','supplementary_debits') AND referenced_table_name IS NOT NULL; SELECT COUNT(*) new_checks FROM information_schema.table_constraints WHERE constraint_schema='energy_dss' AND table_name IN ('billing_adjustment_requests','billing_reconciliations','supplementary_debits') AND constraint_type='CHECK';"
& C:\xampp\php\php.exe tools/verify_billing_preservation.php
```

Results: both HTTP 200; global read_only restored to 0; all three new tables empty;
eight new foreign keys and three CHECK constraints; exactly six protection triggers;
all historical data hashes unchanged. Charge #21 remains 845399.60, email #1 remains
sent with its original content, period #12 remains reopened, payment history unchanged.
No real adjustment, email sending, recalculation or period locking was performed.
The restore verification database was not modified. No unresolved deployment errors.

Files created for this deployment: explicit deployment tool, preflight/result JSON,
server-state/pause JSON, this command record, and PHP server stdout/stderr logs.
No business-logic code or migration changes were needed.
