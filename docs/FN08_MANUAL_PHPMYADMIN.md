# FN-08 manual deployment

Application source is validated; production migration and fresh backup/restore are
not performed by this implementation. Database changes are exclusively manual.

## Source and tests

Readiness reads metadata only and distinguishes missing, incomplete, unavailable
and ready schemas. It verifies InnoDB, required column types, primary/context/alert
indexes, five same-database restrictive foreign keys and exact BEFORE UPDATE/DELETE
protection trigger bodies. Missing or incomplete storage preserves the contextual
preview and blocks later-state persistence; original recommendations are not overwritten.
Initial generation retains the existing legacy model and rule templates.

The generator requires a currently active BQL account and the existing permission.
Revision and audit insertion share a transaction and alert row lock. The context
unique key provides database duplicate protection. Closed alerts reject generation.
Advice never sends notifications or executes the suggested workflow action.

Final isolated results: FN-08 63/63, billing 57/57, pure baseline 16/16.
Retained databases: energy_dss_fn08_context_test_manual_20261014_0032 and
energy_dss_adjustment_test_fn08_manual_20261014_0032. An earlier fixture-loader
failure is retained in energy_dss_fn08_context_test_manual_20261014_0030;
the newline parser was corrected and subsequent runs passed.

## Backup first

Arrange a maintenance window with users, background workers and direct database
clients; no automatic service management is part of this workflow. In phpMyAdmin,
select energy_dss, Export, Custom, SQL, all tables, structure and data. Include
triggers, routines and events, preserving AUTO_INCREMENT and foreign keys. Use
a transaction export option if available, while also preventing concurrent DDL.
Save outside the web root. Verify the actual SQL contains all 32 table definitions
and data, six triggers and the one stored routine (zero events currently expected).
Check completion, nonzero size, SHA-256 and errors. A completed download alone is
not proof of a restorable backup. Restore manually only into a new isolated database
after checking USE/CREATE DATABASE/schema-qualified targets, then compare schema,
all data and critical records. Never invoke cleanup_demo_keep_112026.

## Manual migration

Select energy_dss explicitly in phpMyAdmin. Capture the original recommendation #4,
alert #5 and inspection case #2 before executing SQL. Check the new object names
are absent. Use the SQL tab to run the unchanged migration from
database/migrations/20261014_recommendation_revisions.sql: first CREATE TABLE,
then the DELIMITER-wrapped block containing the two CREATE TRIGGER statements.
If the interface rejects delimiter directives, stop; use the Import tab for the
original SQL file rather than rewriting the trigger bodies. Verify every statement
and do not use ignore-errors options.

## Verification and recovery

SHOW CREATE TABLE recommendation_revisions must show the reviewed column types,
PK, unique key, indexes and five restrictive foreign keys. SHOW CREATE TRIGGER
for both rec_revision_no_update and rec_revision_no_delete must show the reviewed
table, timing, event and unconditional SIGNAL bodies. Compare the saved historical
rows exactly. Expected total inventory becomes 33 tables and eight triggers.
The new table must still be empty; the migration does not create recommendations.

Open /alerts/5 with an existing BQL session for read-only UI verification. Do not
click generation or workflow controls as part of deployment verification.

MariaDB 10.4 DDL commits separately; a failed statement does not undo earlier CREATEs.
Stop at the first error, save its exact text, inspect existing object definitions,
and keep all objects/data. Execute only separately reviewed missing statements;
never blindly rerun, DROP, overwrite historical records or restore over production.
If an object differs from the reviewed definition, stop for diagnosis. Application
readiness blocks new revision writes when schema protection is incomplete.
