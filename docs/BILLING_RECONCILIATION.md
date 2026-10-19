# Đối soát sau phát hành — phạm vi triển khai tối thiểu

## Safety and migration gate

Active project: `energy-dss/` inside the workspace. The nested duplicate project is not modified.
The active `.env` remains `DB_DATABASE=energy_dss`.

Inspected actual `SHOW CREATE TABLE` definitions, billing controllers/routes/services,
stale detection, notice rendering/delivery/retry, closing validation, and role permissions.
Charge IDs are BIGINT UNSIGNED; user IDs are INT UNSIGNED; money uses DECIMAL(14,2).
Existing email foreign keys link `apartment_charge_id` to the original charge.
The original charge is still confirmed although its bill notice was successfully sent.

Proposed explicit migration: `database/migrations/20261013_billing_reconciliation.sql`.
It creates **only**:

- `billing_adjustment_requests`: immutable submitted content, terminal decision metadata;
- `billing_reconciliations`: one immutable approved reconciliation per original charge;
- `supplementary_debits`: one immutable positive debit per reconciliation/charge.

Foreign keys reference the real charge/user IDs with restricted deletion. CHECKs protect
amounts and initiator/approver separation. Unique keys protect idempotency, reconciliation,
and debit creation. Six triggers prohibit changing/deleting approved documents, deleting
requests, or changing submitted content/invalid request transitions.
There are no changes to existing table definitions, historical rows, email content,
payments, prices, readings, or audit events. No backfill and no startup migration hook.

**Live migration deployed on 13 October 2026 after separate authorization.**
Backup restore rehearsal and exact pre/post historical-data comparisons passed.
Exactly three empty tables and six protection triggers are installed; no real proposal,
approval, debit, email or period lock was created. See
`storage/billing-deployment-result.json` and `storage/billing-deployment-commands.md`.
The procedure below records the safety requirements used for deployment.
DDL is not transactionally atomic in MariaDB.
Before separately authorized migration: pause application writes, create a full
`mysqldump --single-transaction --routines --triggers --events` backup of `energy_dss`
using configured local credentials without printing them; verify nonempty output,
checksum, exit status and schema coverage. Keep the backup outside web-served directories
with restricted access. Inspect triggers/table existence and server CHECK enforcement.
Apply the reviewed file with an explicit database target, then verify every table,
constraint and trigger. If DDL partially fails, keep writes paused and diagnose it;
do not reset or recreate the database. No automatic destructive rollback is provided.
Schema presence checks disable posting before deployment. Live installation verification
must establish that all migration triggers are present before enabling writes.
Runtime readiness checks require all three tables and all six triggers; a partial
installation fails closed.

## Rules

A successfully sent bill_notice, pending bill_notice, `da_phat_hanh`, or `issued_at`
protects a charge from recalculation, debt edits, charge mutations and tier mutations.
The repository guard is shared by existing write paths, independent of migration state.
Email creation/retry locks period then charge and validates captured message content
before recording pending delivery. SMTP is outside the database transaction.
No new workflow action sends email or calls an existing charge-saving calculation.

This implementation supports **full-period parking debits only**, including zero-delta
reconciliation. Accounting explicitly supplies the period-start effective date, full-period
confirmation, explanation and evidence. The date of vehicle entry is never used to infer
applicability. Quantities, rate versions, rates, amounts and original evidence are captured
server-side. Future effective periods, proration, credits, reversals, further adjustments
after an approved reconciliation and supplementary payment collection are outside this
minimal scope. Later relevant input changes block closing rather than silently altering
an approved decision/document.

Electrical/water tier changes, other amount changes, apartment input changes and
nonparking rate-version changes cannot be resolved by this parking reconciliation.
Submitted and approved fingerprints include original charge fields, original audit hash,
sent-message content hashes, tier details, readings, vehicle quantities and fresh calculation
snapshot. Approval captures fresh evidence under period/charge/apartment locks.
Permissions are checked against the current active user row, not only session role.
Accounting initiates/withdraws; BQL approves/rejects; initiator cannot decide their own
request even after a role change. Rejection/withdrawal/stale-input retains history and
permits a new submission. Reason is required for every decision.

Approval, reconciliation, optional positive debit and audit are one transaction. Zero
delta creates reconciliation only. Repeated submission/approval is idempotent. One
period/charge lock serializes submissions and approvals; DB unique constraints provide
additional duplicate protection. If an audit write fails all financial posting rolls back.

The original consistency diagnosis remains unchanged. Closing accepts only a **stale**
(not stale_error) charge whose approved request, immutable reconciliation, exact current
fingerprint and debit amount all agree, while still requiring lockable charge status.
Every other existing draft/missing reading, pending import, missing charge, unconfirmed
charge and unrelated stale condition remains enforced. Nothing automatically locks.

Original payments remain linked only to the original charge. Original and supplementary
balances are separate; combined liability adds the debit once. Supplementary recorded
payments are always zero in this phase because collection/allocation is not implemented.
Do not use the original payment reference to collect the supplement. No automatic
allocation or changes to legacy payment reporting/history are made.

## Screens and actions

- `GET /billing-adjustments`: Vietnamese request queue, sidebar navigation.
- `GET /charges/{id}/adjustments`: original/supplement balances, captured calculation,
  proposal form and reconciliation history. Linked from original billing detail.
- `POST /charges/{id}/adjustments`: Accounting submits (no draft DB rows).
- `GET /billing-adjustments/{id}`: Management review, evidence, decision history.
- `POST /billing-adjustments/{id}/approve`: BQL decision, atomic posting.
- `POST /billing-adjustments/{id}/reject`: BQL rejects with reason.
- `POST /billing-adjustments/{id}/withdraw`: initiating Accounting account withdraws.
- `GET /supplementary-debits/{id}`: immutable supplementary document detail.

All routes require auth/RBAC; every POST requires CSRF. Forms use existing layout/styles,
escaped output, PRG redirects and Vietnamese labels. No approval or email is automatic.

After approved live migration, Accounting opens `/charges/21/adjustments`, verifies two
electric bicycles at rate #8 (100000 each) and one motorcycle at rate #9 (50000), enters
`2026-11-01`, confirms full-period applicability and supplies the business decision as
evidence. Proposal is 250000.00. A different BQL account reviews and explicitly approves.
The original remains 845399.60; combined liability becomes 1095399.60. Accounting can
then review period #12 lock blockers; actual locking requires its own explicit action.
None of those live actions are performed as part of this implementation.

## Tests and live preservation

Run `php tests/billing_adjustment_test.php energy_dss_adjustment_test_<unique_suffix>`.
The runner rejects other names and any existing test database. It creates a new isolated
MariaDB database with schema-only definitions and synthetic fixture inserts; no demo
import, production data copy, live tests, reset, DROP or TRUNCATE. Test databases are
retained. Do not run existing broad test runners against the live database.

Coverage: real 845399.60 original/250000 difference and unchanged paid original;
roles/self-approval; issuance/tier/debt guards; full-period applicability; idempotency;
rejection/withdrawal/stale/zero paths; immutable triggers; closing checks; audit rollback;
two simultaneous approval processes; POST auth/CSRF declarations; Vietnamese document UI.

`tools/verify_billing_preservation.php` uses SELECT-only read-only transactions and emits
only row counts and SHA-256 hashes. Compare `storage/billing-preservation-before.json`
and `storage/billing-preservation-after.json`. It checks charge #21, tier details, email #1,
original audit, and all charges/readings/prices/payments/emails/audit events. It neither
renders notices nor performs login, approval, delivery or financial writes.

### Validation completed

- Adjustment integration suite: **57/57 PASS**, MariaDB database
  `energy_dss_adjustment_test_20261013_e` (retained, no DROP).
- Existing pure baseline regression suite: **16/16 PASS**.
- Syntax checks: **21 changed/new PHP files PASS**.
- Exact before/after live SHA-256 report comparison: **unchanged** for every reported set.
- Charge #21: `total_due=845399.60`, `status=da_xac_nhan`.
- Email #1: `status=sent`, content hash
  `36b8499301267365cfe239b2d4d7d59a6629c5cd4f023678d6bf2d1fde15ed24`.
- Period #12: `status=mo_lai`; all proposed live tables absent.
- Earlier isolated iterations `_a` through `_d` are also retained. They contain only
  schema and synthetic test fixtures, never copied production business data.
- No live backup was taken because the live migration has not been authorized or run.
  Backup creation and verification must precede any separately authorized live migration.

### File manifest for this implementation

New:

- `app/Services/IssuedChargeGuard.php`
- `app/Services/BillingAdjustmentService.php`
- `app/Repositories/BillingAdjustmentRepository.php`
- `app/Controllers/BillingAdjustmentController.php`
- `resources/views/billing_adjustments/index.php`
- `resources/views/billing_adjustments/charge.php`
- `resources/views/billing_adjustments/show.php`
- `resources/views/billing_adjustments/debit.php`
- `resources/views/billing_adjustments/balances.php`
- `resources/views/billing_adjustments/history.php`
- `database/migrations/20261013_billing_reconciliation.sql`
- `tests/billing_adjustment_test.php`
- `tests/support/adjustment_approval_worker.php`
- `tests/support/reconciliation_schema.sql` (schema only, no demo/business rows)
- `tools/verify_billing_preservation.php`
- `docs/BILLING_RECONCILIATION.md`

Modified:

- `app/Repositories/ApartmentChargeRepository.php`
- `app/Services/BillingCalculationService.php`
- `app/Services/EmailNotificationService.php`
- `app/Services/PeriodClosingService.php`
- `config/permissions.php`
- `routes/web.php`
- `resources/views/billing/show.php`
- `resources/views/partials/sidebar.php`

Verification artifacts (no message bodies or credentials):

- `storage/billing-adjustment-test-results.txt`
- `storage/billing-preservation-before.json`
- `storage/billing-preservation-after.json`

The previously implemented baseline fix/tests were not changed by this work.
