# Implementation Gate Checklist

**Status as of 2026-11-07:** **G1 Passed by Chairman disposition**; G2–G7 remain **Open / not passed**. G1 acceptance is governance evidence only; the package contains no implementation verification evidence. A gate closes only when its evidence is recorded, the named verification activity is completed, acceptance criteria are met, and the applicable authority records acceptance.

Passing a gate is necessary for the activity it blocks; it does not itself authorize application development. Chairman/KAB authorization and scoped work approval remain separate decisions. Hybrid active-active remains excluded at launch and is not authorized by passing any gate below.

## G1 — Architecture and governance closure

- **Required evidence:** Recorded KAB disposition; reviewer-source limitation or final assessment letters; Chairman decisions for launch boundaries, RLS/data placement, licence policy, support emergency access, reconciliation tolerances, jurisdiction/data class, privacy owners, and initial workflow.
- **Verification activity / responsible:** KAB secretary checks provenance and decision record; Chairman reviews and records each outstanding decision; architecture owner reconciles the approved decision states and scope.
- **Acceptance criteria:** Both reported outcomes are accurately recorded as APPROVE WITH CONDITIONS; no missing reviewer wording is attributed; all decisions required before foundation or module work have an explicit owner and due gate; architecture and implementation authorization are recorded separately.
- **Blocks:** Any application scaffolding or implementation work pending explicit Chairman authorization; tenant/business module scope remains blocked.
- **Status:** [x] Passed — Chairman disposition and Task 007 foundation authorization recorded in [Chairman G1 resolution](chairman-g1-task007-authorization.md).

## G2 — Tenant and runtime proof

- **Required evidence:** PostgreSQL 15+ runtime-role grants; forced RLS/default-deny policies; table/policy coverage; connection reset/eviction behavior; security-invoker view tests; automated cross-tenant/property test results across Filament, Livewire, APIs, exports/files, queues/schedules, Octane, pooled connections, and error/retry paths; CI rule output and reviewed exceptions.
- **Verification activity / responsible:** Security and platform engineering execute adversarial tests under production-like non-owner roles; database owner reviews grants, RLS, view behavior and reset failures; independent reviewer records results.
- **Acceptance criteria:** Runtime cannot bypass RLS or access another account/property; missing/stale context fails closed; no context survives alternating requests/jobs or pool reuse; unsafe access and unprotected tenant tables are detected; reset failure evicts the connection.
- **Blocks:** Creation/use of tenant business tables and any tenant-scoped business module.
- **Status:** [ ] Open — local core verification passed (7 PostgreSQL tests plus 1 rollback-failure unit test; 37 assertions total) and hosted workflow [G2 tenant security proof #1](https://github.com/Kelvinnyachae/HotelPMS_POS/actions/runs/42413331998) passed on commit `5c2afd4`; see [G2 tenant runtime evidence](g2-tenant-runtime-evidence.md). Integration-wide adversarial coverage, real connection reset/eviction proof, production grant review, and independent security review remain outstanding. Remaining verification is authorized by the Chairman; see [Chairman G2 verification authorization](chairman-g2-verification-authorization.md).

## G3 — Ledger immutability

- **Required evidence:** Runtime and owner-role privilege review; database mutation-denial results; posting procedure validation evidence; balanced-journal and scope/idempotency/period checks; reversal tests; privileged access audit controls.
- **Verification activity / responsible:** Database owner and finance/security reviewers inspect grants, triggers/procedures and execute mutation/reversal cases using runtime and privileged roles.
- **Acceptance criteria:** Runtime cannot update/delete/truncate posted finance or inventory ledgers; invalid/imbalanced postings cannot commit; corrections are linked reversals; privileged paths are restricted and audited.
- **Blocks:** Financial posting and inventory-ledger implementation or use.
- **Status:** [ ] Open — no evidence recorded.

## G4 — Transaction and reconciliation proof

- **Required evidence:** Concurrent inventory/reservation test results; movement/projection/outbox atomicity evidence; duplicate/idempotent retry cases; deterministic reconciliation outputs with known variances; projection rebuild comparison; approved accounting/inventory policy and close thresholds.
- **Verification activity / responsible:** Domain engineering runs concurrency/replay scenarios; finance and operations verify reconciliation results, exception workflow and close blocking; reviewers inspect transaction boundaries and idempotency constraints.
- **Acceptance criteria:** No oversell or balance drift under the approved policy; movements, projections, and outbox are atomic; duplicate work is harmless; reconciliation detects and routes variances; material unresolved variance blocks close; rebuild agrees with ledger.
- **Blocks:** Inventory/reservation transactions and financial close/reconciliation implementation.
- **Status:** [ ] Open — no evidence recorded.

## G5 — Deployment, offline licence and telemetry

- **Required evidence:** Independent cloud and standalone install/upgrade/backup/restore/recovery qualification; signed bundle and licence test results for binding, rotation, expiry, grace, replay and clock rollback; safe-operation scenarios; telemetry authentication, replay, minimization and idempotency results; Chairman C-02/C-03 decisions.
- **Verification activity / responsible:** Release engineering qualifies both profiles; security verifies signing, key custody, telemetry and replay defenses; product/Chairman verifies permitted grace and launch profile.
- **Acceptance criteria:** Each claimed profile completes recovery and update scenarios; invalid/replayed/expired manifests fail safely; allowed grace preserves only approved operations; telemetry is outbound, authenticated, minimized and deduplicated; no operational DB query path exists.
- **Blocks:** Marketing or production support of either profile and implementation of offline licensing/telemetry. Hybrid active-active remains prohibited unless a separate ADR and explicit approval/qualification are completed.
- **Status:** [ ] Open — no evidence recorded.

## G6 — Support, privacy and tenant lifecycle

- **Required evidence:** Support grant/authentication/expiry/revocation/read-audit results; target-market counsel approval; data-class and retention schedule; export/offboarding/hold/erasure evidence across plaintext columns, DEKs/wrapped copies, backups, object versions, caches, indexes, replicas and support bundles; payment-provider responsibility record.
- **Verification activity / responsible:** Security/support tests privileged access; privacy/legal counsel approves jurisdictional duties; operations performs an erasure/restore exercise; payment/security owner confirms no raw PAN enters the application.
- **Acceptance criteria:** Support is scoped, step-up authenticated, visible, revocable, time-limited and dual-audited; guest-data obligations are approved; erasure handles legal holds and plaintext as well as keys; no raw card data is stored or processed by Dawineks application.
- **Blocks:** Guest data collection, support access to client data, and production tenant offboarding until relevant evidence is accepted.
- **Status:** [ ] Open — no evidence recorded.

## G7 — Operational readiness and first vertical slice

- **Required evidence:** Approved and measured RPO/RTO, uptime, latency and capacity targets; tenant-only and full restore drills; backup-key recovery; signed update/migration recovery; alerts, incident/support, finance-close and on-prem runbooks; KAB-approved vertical-slice scope and acceptance criteria.
- **Verification activity / responsible:** SRE/operations execute drills and workload measurements; security/release reviewers inspect recovery/update evidence; KAB reviews and accepts the proposed first vertical slice.
- **Acceptance criteria:** Targets are met with recorded results; restore and recovery drills succeed; runbooks and monitoring are operational; the slice is scoped, accepted and has its prerequisite gates passed.
- **Blocks:** Production pilot, release, and any business-module work outside a separately authorized first slice.
- **Status:** [ ] Open — no evidence recorded.

## Gate record

| Gate | Status | Evidence reference | Verifier / acceptance record |
| --- | --- | --- | --- |
| G1 | Passed — governance decision | chairman-g1-task007-authorization.md | Chairman authorization recorded 2026-11-06 |
| G2 | Open — core local verification passed; remaining verification authorized | g2-tenant-runtime-evidence.md; chairman-g2-verification-authorization.md | 7 PostgreSQL tests + 1 rollback-failure unit test; 37 assertions total; no G2 acceptance record |
| G3 | Open — not passed | None recorded | None |
| G4 | Open — not passed | None recorded | None |
| G5 | Open — not passed | None recorded | None |
| G6 | Open — not passed | None recorded | None |
| G7 | Open — not passed | None recorded | None |
