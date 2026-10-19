# FN-08 context-aware recommendations — source implementation and gated persistence

## Read-only audit and root cause

Production `energy_dss`, inspected 14 October 2026. Actual alert #5 is `da_xac_nhan`;
inspection case #2 is `da_dong` with `result_conclusion=canh_bao_dung`.
Audit #818 (`CONFIRM_ALERT_DATA`) explicitly records `decision=confirm`, `case_id=2`,
`adjusted=null`. Its original reading remains confirmed at 310 kWh. Threshold audit
records baseline 181.67 kWh, +70.64%, operator `gt`, threshold 30%.

The actual saved recommendation is **#4**, alert #5 / recommendation rule #2, not #1.
No recommendation #1 currently exists. Its English original text remains unchanged.

Flow: `routes/web.php` POST `/alerts/{id}/recommendations` ->
`AlertController::generateRecommendations` -> `RecommendationService::generateForAlert`
-> enabled `RecommendationRuleRepository::activeRules` ->
`RecommendationRuleEvaluator::evaluate` / `consecutiveAlerts` ->
`RecommendationRepository::exists` and insert -> audit `GENERATE_RECOMMENDATIONS`.

`RecommendationRuleEvaluator::evaluate` (line 30) checks configured utility and one of
three FN-08 conditions. `consecutiveAlerts` (line 76) checks alert existence across N
periods; neither method decides whether verification already finished. The original
generation function rendered the same configured inspection text after Management
confirmation. This is the root cause, not an incorrect threshold calculation.

`RecommendationRepository::exists` (line 17) enforces one recommendation per
alert/rule; live `uq_rec_alert_rule(alert_id,recommendation_rule_id)` makes workflow
revisions impossible in that table without deleting/overwriting history or changing
the unique key. The inspected schema has no supersession/revision columns.

Lifecycle source: `AlertStateMachine::TRANSITIONS`; inspection request/result and
`AlertVerificationService::confirm` explicitly close the case and emit
`CONFIRM_ALERT_DATA` with `confirm`/`adjust`, case ID and adjusted reading evidence.
Alert status alone cannot establish which Management decision occurred.

Permissions/routes remain unchanged: BQL manages/views/generates FN-08;
Technician submits assigned technical results; Accounting cannot generate FN-08.
The generator also validates current active BQL identity server-side.

## Business policy and precedence

The existing three DB recommendation conditions and FN-07 thresholds are unchanged.
An enabled, matching FN-08 rule is still required. The lifecycle policy is applied
before generic text for a later workflow state; no AI or production-specific IDs.

| Actual workflow state | Proposed next step |
|---|---|
| `moi` / `da_review`, no unresolved recheck | Verify readings, meter condition and usage; never assert a fault |
| `can_kiem_tra_lai`, latest case `da_giao` | Monitor assigned recheck and await evidence; no duplicate request |
| `can_kiem_tra_lai`, case `da_co_ket_qua`, findings present | Management review; technician finding does not finalize the decision |
| `da_xac_nhan`, case closed, matching audited `confirm`, confirmed unchanged reading, true threshold evidence and valid technician result | Resident communication proposal, unusual usage review, savings and follow-up; no repeated inspection without a new reason |
| Closed case and Management confirms data, but genuine anomaly evidence incomplete | Review findings; do not invent a valid anomaly or meter fault |
| Matching audited `adjust` for the actual reading/current value | Reconfirm corrected data and reevaluate; do not assume old anomaly remains valid; preserve issued billing |
| `da_dong` | Historical display only; reject new generation |
| Unknown/missing/contradictory cases or decisions | Explicit safe reason; no actionable recommendation |
| Explicit reopening | Invalidate earlier confirmation; fresh verification with a new reason |

The resolver uses the latest relevant case, matching Management audit, reading,
alert utility/priority/comparison, original FN-07 threshold snapshot and reopening
history. Four-decimal alert values are compared exactly with BCMath, not float.
Case result received and case closed are distinguished; neither is inferred from
text notes. Source findings and decisions remain unmodified.

Policy categories/Vietnamese templates are explicit configuration in
`config/recommendation_workflow.php`, version `fn08-workflow-v1`. Rule matching still
uses enabled DB configuration. Policy changes and rule changes are captured in
immutable context fingerprints and snapshots.

## Persistence compatibility and migration gate

**Proposed migration: `database/migrations/20261014_recommendation_revisions.sql`.
NOT APPLIED TO PRODUCTION.** It is used only in isolated tests.

One additive InnoDB table `recommendation_revisions` references:

- original alert and FN-08 rule;
- optional original recommendation;
- optional previous revision;
- generating BQL user.

It stores category, workflow stage, Vietnamese content, context SHA-256 and full
rule/condition/workflow evidence snapshot. UNIQUE(alert_id,rule_id,context_hash)
prevents duplicate state/configuration revisions. Two SIGNAL triggers prevent
UPDATE and DELETE. All foreign keys restrict deletion. No old tables/indexes/rows
are altered, no backfill or seed is included, and there is no startup hook.

Generation locks the alert in the existing transaction, the same lock used by
recheck and Management transitions. Revision creation and audit commit atomically.
Repeated or concurrent generation under identical conditions returns duplicates.
Later context can append a new revision; historical texts/snapshots stay intact.

Initial legacy generation keeps existing DB template semantics and unique key.
The contextual Vietnamese proposal is shown separately. Later-state/reopened
generation requires the new table AND its two immutability triggers; before
migration it fails closed and the UI shows a read-only proposal and storage warning.
It never falls back to overwriting an original recommendation.

UI historical originals are marked `Lịch sử khuyến nghị gốc` and displayed verbatim,
even if English. Revision current/history labels are derived from exact current
context/rule fingerprints; no historical row is changed to mark it superseded.
The original and previous-revision links provide explicit append-only lineage.

### Separate approval and deployment plan

1. Obtain explicit approval for the SQL file; this task does not authorize deployment.
2. Take a **fresh** complete backup. Earlier billing backup predates new FN-07/FN-08
   records and must not be reused as the current backup. Verify isolated restore,
   data hashes, existing financial triggers, routines and schema.
3. Pause writers and concurrent DDL; verify migration names do not already exist.
4. Apply only reviewed additive CREATE statements once, stop immediately on error.
5. Verify table, five FKs, unique key, two triggers and runtime readiness; compare
   all historical production hashes. MariaDB DDL is not an all-or-nothing transaction.
   On partial failure preserve history and inspect missing objects; do not reset,
   drop tables or blindly rerun.
6. Only a later explicit BQL action may append a live recommendation revision.
   No live revision is generated by this implementation or migration.

## Regression validation

`php tests/fn08_context_test.php energy_dss_fn08_context_test_<new_unique_suffix>`
creates a new isolated schema and synthetic fixtures, aborting if it exists.
Schema-only fixture file contains no production rows or credentials. Test databases
are retained; there is no DROP/TRUNCATE or production cleanup. Test-only lifecycle
transitions exercise actual FN-07 services and confirm their interactions with FN-08.

Coverage includes initial/reviewed/assigned/result/confirm/adjust/closed/reopened and
unknown cases; matching utility and threshold evidence; unchanged configured initial
templates; duplicate and two-process concurrent generation; rule toggles/changes;
case-linked audited decisions; immutable legacy/revision history; no automatic
reading/baseline/price/billing/supplement/payment/email/lock changes; BQL/KT/Accounting
permissions; preserved FN-07 threshold detection and draft-reading skip behavior.

Final results: **54/54 FN-08 checks PASS** in retained database
`energy_dss_fn08_context_test_20261014_e`; **57/57 existing isolated billing checks
PASS** in `energy_dss_adjustment_test_fn08_regression_20261014`; **16/16 existing
pure baseline checks PASS**; syntax checks on all **8 changed/new PHP files PASS**.
The FN-08 view was visually inspected using a local read-only preview. All 32 live
table row counts/hashes match the pre-work snapshot. The production revision table
is absent, and no live recommendation or audit event was generated by these checks.

Existing `tests/run_fn08_tests.sh` was inspected but deliberately not executed: it
drops test databases and invokes a production-targeted HTTP suite with data cleanup.
Those operations violate this task. Its condition/placeholder/idempotency/history
contracts are exercised by the new safe isolated suite instead. Existing pure
baseline tests and isolated billing reconciliation regression suite are also run.

Production proof: SELECT-only hashes of **all 32 tables** before and after are in
`storage/fn08-production-before.json` and `storage/fn08-production-after.json`.
`storage/fn08-readonly-verification.json` confirms alert #5's read-only proposal is
`verified_confirm`, matches FN-08 rule #2, has no revision storage deployed and
preserves original recommendation #4. The verification only reads; no generation,
audit mutation, email, workflow transition, billing or migration is invoked.

## Manual UAT

On production, **read-only only**: sign in using an existing BQL session and open
`/alerts/5`. Verify `Theo dõi sau xác minh`, resident/savings/follow-up text, reason,
rule #2, expandable evidence, original recommendation #4 in history, and the
NOT-DEPLOYED storage warning. Do not click other workflow controls or send emails.

On the isolated test/UAT database with the proposed migration: BQL may explicitly
generate at each stage; verify no repeated inspection after confirmation, no decision
on behalf of Management, no new actions on closed alerts, and no duplicates on repeat.
Check KT/Accounting cannot generate and existing technical role visibility remains.

## Impact and files

Only FN-08 source/presentation and isolated verification resources are changed.
FN-07 evaluation rules, lifecycle transitions, readings, baselines, prices, billing,
reconciliation, supplementary debits, payments, email delivery and period closing
are not modified. Their DB records remain unchanged.

Modified: `app/Services/RecommendationService.php`, `app/Controllers/AlertController.php`,
`resources/views/alerts/show.php`, `public/assets/css/app.css` (FN-08-scoped rules only).

Added: `app/Services/RecommendationWorkflowContext.php`,
`app/Repositories/RecommendationRevisionRepository.php`,
`config/recommendation_workflow.php`, proposed migration SQL,
`tests/fn08_context_test.php`, `tests/support/fn08_context_worker.php`,
`tests/support/fn08_context_schema.sql`, and this document.
Hash reports/test outputs in `storage/` are verification artifacts only.
