# ADRs and Architecture Decision Register

These records describe the architecture decisions for Chairman review. ADR-002, ADR-003, and ADR-011 are Ratified by the Chairman on 2026-11-06. A bounded Task 007 implementation authorization is recorded separately in the Chairman G1 resolution. A decision-state label describes governance disposition only; it never by itself authorizes implementation.

## Decision states

- **Proposed** — awaiting Chairman decision.
- **Ratified** — formally approved by the Chairman. ADR-002, ADR-003, and ADR-011 are Ratified.
- **Superseded** — replaced by a later decision; retained for history.
- **Deferred** — deliberately postponed for a stated reason or trigger.

An architectural direction may be proposed or ratified while implementation remains separately gated. G1 is Passed as a governance decision; G2–G7 remain open. The separate Task 007 authorization covers only the bounded foundation scope; tenant business tables and features remain blocked until G2 acceptance.

## Decision register

| ADR | Decision | State | Implementation boundary |
| --- | --- | --- | --- |

| ADR-002 | PostgreSQL tenancy, forced RLS, role separation | Ratified | Tenant business tables blocked until G2 acceptance. |
| ADR-003 | Transaction-local tenant context and sanitized pooled connection lifecycle | Ratified | Tenant business tables blocked until G2 acceptance. |
| ADR-004 | Cloud SaaS and standalone on-premise profiles; hybrid active-active excluded at launch | Proposed | Each profile blocked until G5 qualification and C-02 decision. |
| ADR-005 | Laravel queue with transactional outbox/inbox | Proposed | Integration implementation follows scope approval and relevant G4/G5 evidence. |
| ADR-006 | PostgreSQL transaction/row locks and constraints for reservations/inventory | Proposed | Inventory/reservation implementation blocked until G4 acceptance. |
| ADR-007 | Central entitlement service and signed offline validity manifest | Proposed | Licensing implementation blocked until C-03 and G5 acceptance. |
| ADR-008 | Deterministic domain-specific offline conflict handling | Proposed | Any cross-site/cloud synchronization remains excluded; separate ADR and qualification required. |
| ADR-009 | Immutable financial/inventory ledgers and PostgreSQL transaction controls | Proposed | Finance/inventory implementation blocked by G3/G4 and relevant policy decisions. |
| ADR-010 | Account → legal entity → property → outlet hierarchy | Proposed | Tenant tables and business use remain subject to G2 and domain scope approval. |
| ADR-011 | UUIDv7 identifiers | Ratified | Foundation implementation waits for C-09 compatibility decision. |
| ADR-012 | Filament platform panel; client interface selected after workflow validation | Proposed | UI/framework choice does not authorize application scaffolding. |
| ADR-013 | Authenticated outbound telemetry; no inbound control-plane tunnel | Proposed | Telemetry implementation subject to G5 and C-02. |
| ADR-014 | Tenant DEK erasure and hold-aware data lifecycle | Proposed | Guest data blocked until G6 and C-07 acceptance. |
| ADR-015 | PostgreSQL security-invoker views for tenant data | Proposed | Tenant views blocked until G2 evidence and runtime-role verification. |
| ADR-016 | Advanced technologies (OPA, Kafka/RabbitMQ, generic CRDTs, microservices) | Deferred | Revisit only on measured need, with a separate ADR; not launch prerequisites. |
| ADR-017 | Consolidated platform/client workspace boundaries; modular Laravel monolith | Proposed | No implementation before applicable G1/G2 decisions and evidence. |


## ADR-017 — Consolidated platform and client workspace boundaries (supersedes historical ADR-001)

**State:** Proposed — awaiting Chairman decision.

**Context:** Dawineks needs a central administrative control plane and isolated client workspaces for independent and multi-property hospitality operators.

**Decision proposed:** Use a modular Laravel application with separate platform and client surfaces, identity domains, policies, routes, cookie/session scopes, and database connections/roles. The platform Control Panel has no operational-table grants. Platform services receive authenticated tenant-pushed aggregates, not operational queries. No microservices at launch.

**Consequences:** A simpler initial structure with explicit privilege boundaries and separable modules. Platform MFA/network controls and client membership policies remain required.

**Implementation authorization:** None. Any build work remains subject to G1/G2 and a separate Chairman authorization.

## ADR-002 — PostgreSQL tenant boundary and connection lifecycle

**State:** Ratified — Chairman approval recorded 2026-11-06.

**Context:** ORM scopes alone cannot prevent leakage from Filament, Livewire, jobs, raw SQL, or worker reuse.

**Decision proposed:** PostgreSQL 15+; Account is the RLS key. Start with a shared tenant database and separate platform/client/migration roles and credentials. Force RLS on tenant tables; deny access without context; use composite ownership constraints and security-invoker views; runtime roles are non-owners without `BYPASSRLS`. Resolve membership server-side and set context transaction-locally. Pool checkout/check-in rolls back and resets session state; evict connections on reset failure. Never rely on session-scoped account settings.

**Required evidence:** G2 production-like runtime-role tests across Filament, Livewire, queues/schedules, Octane, APIs/exports, pool reuse, failures, and cross-account/property access; CI table/policy coverage and unsafe-query checks.

**Residual risk:** RLS does not protect against arbitrary SQL using a compromised runtime credential that can set a custom GUC. Least privilege, safe queries, credential protection, and dedicated tenant databases for higher assurance remain necessary.

**Implementation authorization:** Tenant business tables are blocked until G2 acceptance and separate authorization.

## ADR-003 — Tenant context and connection lifecycle

**State:** Ratified — Chairman approval recorded 2026-11-06.

**Decision proposed:** Establish tenant account/property context only inside a transaction after revalidating the principal’s membership. Use transaction-local context, rollback/reset mutable connection state at pool boundaries, and evict connections when sanitization fails. Never rely on session-scoped tenant identity.

**Implementation authorization:** Tenant tables and tenant-scoped business code are blocked until the G2 runtime, pool, and cross-surface proof is accepted.
## ADR-004 — Cloud and standalone on-premise deployment boundary

**State:** Proposed — awaiting Chairman decision.

**Context:** Hospitality sites need local operations during WAN outages, while SaaS needs managed multi-account operation.

**Decision proposed:** Define two separately qualified profiles using shared core code. Cloud SaaS is multi-account and managed by Dawineks, with shared RLS data plane and account placement. Standalone on-premise is an isolated local installation for one Account (which may include multiple legal entities, properties, and outlets), with local PostgreSQL, local operations, signed offline licence, and optional authenticated outbound telemetry. The local installation does not expose inbound database access to the Control Panel.

**Explicit exclusion at launch:** Cloud and local installations do not concurrently write the same reservations, inventory, or finance. No transparent bidirectional database replication, active-active edge, or automatic cross-site conflict merging. Local standalone offline authority and deterministic domain-specific conflict rules apply.

**Consequences:** Each profile requires independent install, update, backup/restore, licence, recovery, and security qualification. On-premise commercial availability requires C-02 and G5 acceptance.

**Implementation authorization:** Neither profile is authorized for build by this proposed ADR. Hybrid active-active remains excluded unless separately approved by ADR and qualified.

## ADR-005 — Asynchronous integrations

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** Commit domain mutation and outbox row atomically. Laravel queue delivers at least once; outbox remains durable/replayable. Consumers use inbox/idempotency records in the same transaction as side effects. Provider adapters retry with bounds and reconcile ambiguous outcomes. Credentials are vault/KMS references; APIs are versioned and webhooks signed/deduplicated.

**Deferred:** Kafka/RabbitMQ, integration microservices, generic event sourcing/CQRS, and CRDTs are not launch prerequisites. Reconsider only when measured throughput, fanout, replay, or isolation requirements justify the burden and a separate ADR is approved.

**Implementation authorization:** Subject to scoped workflow approval and applicable G4/G5 acceptance.

## ADR-006 — Transactional locking for inventory and reservations

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** Use PostgreSQL transactions, deterministic row-lock ordering, constraints, and idempotency as the correctness authority for reservations and inventory within one database. Use a saga for cross-database transfers; Redis Redlock is not a correctness authority.

**Implementation authorization:** Inventory/reservation implementation is blocked until the applicable G4 concurrency and reconciliation evidence is accepted.

## ADR-009 — Financial and inventory integrity

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** Append-only double-entry GL per legal entity and append-only inventory movement ledger. Database roles, triggers, and controlled posting procedure prevent mutation/deletion of posted entries; deferred validation ensures balanced journals by entity/currency; corrections use linked reversals. Inventory movement, balance projection, idempotency key, and outbox commit atomically. Lock balance rows in deterministic order with PostgreSQL row/conditional locks; use a saga for cross-database transfers.

**Reconciliation:** Compare subledger/GL, tender/provider/bank/fiscal totals, and movement ledger/projection/physical counts. Route variances to exceptions; never silently plug or mutate posted history.

**Implementation authorization:** Finance and inventory work are blocked until G3/G4 acceptance and C-05/C-06 decisions.

## ADR-007 — Entitlement and offline licence validation

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** A Laravel entitlement service is the single decision point across UI/API/jobs. Control Plane signs install/account-bound manifests with features, limits, sequence, key ID, validity, and grace. Tenant caches only verified manifests and validates them using pinned public keys; it tracks sequence/last trusted time and detects replay/clock rollback. No private signing key is deployed to tenants. Safe restricted operations preserve approved export/restore and in-progress settlement.

**Implementation authorization:** Licensing work is blocked until C-03 is decided and G5 is accepted. OPA is deferred unless demonstrated policy needs justify a separate ADR.

## ADR-008 — Offline conflict policy

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** Use deterministic domain-specific conflict rules, authority, sequence, and bounded allotments. Do not use generic CRDT merging for reservations, money, or stock.

**Implementation authorization:** No hybrid active-active or cross-site multi-writer behavior. Such capability needs a separate design, ADR, and qualification.

## ADR-010 — Hospitality hierarchy

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** Account → legal entity → property → outlet, with account as tenant/RLS key. Keep billing identity, tax identity, and operating site distinct.

**Implementation authorization:** Detailed fiscal and ownership rules and G2 evidence precede tenant business tables.

## ADR-011 — Identifier format

**State:** Ratified — Chairman approval recorded 2026-11-06.

**Decision proposed:** UUIDv7 for global/offline uniqueness and tenant moves, subject to runtime/library/index compatibility validation.

**Implementation authorization:** Foundation use waits for C-09 decision.

## ADR-012 — Administrative and client interface

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** Filament is the platform-admin candidate. Select the client interface after workflow validation; add Vue only where justified.

**Implementation authorization:** This proposal does not authorize scaffolding or application code.

## ADR-013 — Control-plane telemetry

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** Tenant-initiated outbound authenticated telemetry with minimized, allowlisted aggregates; no inbound management tunnel or control-plane operational database query path.

**Implementation authorization:** Subject to C-02 and G5.

## ADR-014 — Tenant lifecycle and erasure

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** Use hold-aware offboarding, explicit row deletion/anonymization, tenant DEKs for eligible sensitive payloads, destruction of wrapped key copies, and evidence-backed treatment of backups, caches, replicas, and exports.

**Implementation authorization:** Guest data is blocked until C-07 and G6 acceptance.

## ADR-015 — Tenant-data views

**State:** Proposed — awaiting Chairman decision.

**Decision proposed:** PostgreSQL 15+ security-invoker views for tenant data exposed to runtime roles; base-table RLS and grants remain mandatory and must be tested as the actual runtime role.

**Implementation authorization:** Subject to G2 evidence.

## ADR-016 — Advanced platform technologies

**State:** Deferred — deliberately postponed until measured requirements justify reconsideration.

Kafka/RabbitMQ, OPA, generic CRDTs, Redis Redlock as correctness authority, and microservices are not launch prerequisites. Any adoption requires evidence and a separate ADR. This deferral does not authorize their implementation.




