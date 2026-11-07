# Dawineks Hospitality OS — Consolidated Master Architecture

**Version:** 1.0 draft for KAB / Chairman review  
**Date:** 6 November 2026  
**Status:** Consolidated proposed architecture; no business modules implemented  
**Authority:** Supersedes earlier repository architecture proposals where they conflict. Approved constraints, proposed decisions, open decisions, and deferred scope are labeled below.

## Decision labels

- **Approved constraint** — explicitly authorized strategic requirement or technical principle.
- **Proposed** — recommended architecture to approve before implementation.
- **Chairman decision** — policy or commercial choice that engineering cannot safely infer.
- **Deferred** — intentionally outside foundation scope or assigned to a later phase.

## Executive architecture

**Approved constraints:** Dawineks Hospitality OS has a Central Dawineks Control Panel and isolated client workspaces; Laravel, Filament, and PostgreSQL remain the proposed foundation; begin with a modular monolith; do not start business modules in this task; enforce database tenant isolation; keep financial and stock movements immutable; support cloud and on-premise deployment from shared core code; avoid premature microservices; permit later database separation; prevent default unrestricted platform-admin access to client operations.

**Proposed:** One modular Laravel codebase with separately authorized platform and client surfaces, deployed together initially but with separate data access and DB credentials. Store control-plane account catalogue, identity, billing, entitlements, support grants, audit and aggregate telemetry in a control-plane database. Store client operational data in a tenant data plane, initially shared PostgreSQL with forced RLS. Support moving larger tenants to dedicated databases through a placement registry. Filament is the leading candidate for platform administration; select the client interface after workflow validation, and add Vue only where it has clear value.

For on-premise use, package the same core application and tenant data plane as a customer/site deployment. The Dawineks control plane exchanges signed licence/entitlement and minimized health/usage data with the installation. A disconnected installation continues local operations within an approved licence lease; ordinary site operations do not depend on a live Dawineks connection.

```text
Dawineks Control Plane
  Platform identity + account registry + plans/subscriptions/licences
  support approvals + audit + billing references + aggregate usage
                 │ signed, minimized, asynchronous control messages
                 ▼
Client Application / Tenant Data Plane
  Laravel modular monolith ─ PostgreSQL (RLS) ─ Redis ─ object storage
  platform interface          client workspace       workers / outbox
                 │ PMS / POS / procurement / accounting / channels (phased)
                 └ optional on-premise edge deployment and synchronization
```

The control plane has no ordinary database credential or query path into tenant operational tables. A support grant authorizes a brokered, narrow, audited client-context session; it does not make platform administrators database superusers.

## 1. Master system architecture

### Layers and bounded contexts

1. **Experience:** platform control-panel UI, client workspace UI, property/outlet context selector, and versioned REST API. UI visibility is not an authorization boundary.
2. **Identity and policy:** distinct platform and client principals, platform MFA, scoped memberships, RBAC plus resource policies, and support grants.
3. **Application:** use cases for account administration, entitlement checks, reservations, POS checks, purchasing, accounting, channel synchronization, and reporting. Each owns authorization, validation, transaction boundaries, audit, and outbox behavior.
4. **Domain:** modular contexts communicate through explicit interfaces and domain events. Shared kernel stays small (IDs, money/quantity types, time, tenant context, event envelope). Avoid direct cross-module writes.
5. **Persistence:** control-plane store and tenant data plane; tenant tables carry `account_id` and applicable `property_id`/`outlet_id`, RLS and referential constraints. Redis is not ledger-of-record. Object storage uses tenant-scoped keys and access policies.
6. **Integration:** transactional outbox, idempotent consumers, inbox/deduplication, provider adapters, webhooks, reconciliation and dead-letter/replay.
7. **Operations:** health/metrics, redacted structured logs, audit, backups, deployment/upgrade tooling, alerting, recovery runbooks.

Suggested modules: `Platform`, `Identity`, `Tenancy`, `PropertyDirectory`, `Subscriptions`, `SupportAccess`, `Audit`, `PMS`, `POS`, `Inventory`, `Procurement`, `Accounting`, `Channels`, `Reporting`, `Integrations`. Modules own their tables/migrations, application services, events, and policy entry points. Critical financial/sync behavior must not depend on implicit model observers.

REST resources use `/api/v1`; financial commands accept idempotency keys. Use stable public IDs, pagination, explicit timezone/currency, and object-level authorization. Web and API surfaces call the same application services.

## 2. Account, legal entity, property, outlet data model

| Entity | Meaning | Proposed relationships / constraints |
| --- | --- | --- |
| `Account` | Dawineks commercial customer and tenant security boundary; independent operator or group. | 1:* legal entities, properties, memberships, subscriptions and licences. Immutable tenant key. |
| `LegalEntity` | Customer legal/financial organization with jurisdiction, registration IDs, tax profile and functional currency. | Belongs to one account; an account can have multiple legal entities. Registration IDs are jurisdiction-scoped and access controlled. |
| `Property` | Hotel, resort, lodge, or restaurant site with timezone, calendar, address and configuration. | Belongs to one account and owning legal entity; optional operator/management legal entity. Has outlets and optionally rooms/room types. |
| `Outlet` | Revenue/operating unit: restaurant, bar, spa, shop, room service, banquet, etc. | Belongs to one property/account; accountable legal entity or explicit inheritance rule. Supports restaurant-only operations without hotel room inventory. |
| `PlatformUser` | Dawineks staff identity. | Control-plane identity domain only. |
| `ClientUser` | Person signing into client workspace. | May join multiple accounts via memberships; identity distinct from platform identity. |
| `AccountMembership` | User role/status for one account. | Unique active membership per user/account, or validity-period history. |
| `PropertyMembership` / `OutletMembership` | Narrower role assignment. | Must reference membership in the same account and parent property. |

Account is the tenant boundary, not the legal entity: groups may contain multiple entities while remaining one commercial tenant. Each client-owned row has non-null `account_id`; property-scoped rows also have `property_id`; outlet activity has `outlet_id` where applicable. Enforce parent/account consistency with composite foreign keys (e.g. `(account_id, property_id)`). Financial reports and postings are legal-entity aware.

Additional operational records (later modules) include `RoomType`, `Room`, `Reservation`, `Guest`, `Stay`, `Folio`, `Charge`, `PaymentReference`, `POSOrder`, `POSLine`, `InventoryItem`, `StockLocation`, `PurchaseOrder`, `GoodsReceipt`, `SupplierInvoice`, `JournalEntry`, `JournalLine`, `TaxRule`, `RatePlan`, `ChannelConnection`, and `ExternalMapping`. Scope each by the right account/property/outlet/legal entity. Store money as fixed precision/minor units with currency; never use floating point for accounting. Minimize and protect guest PII.

## 3. Central Dawineks Control Panel architecture

Control plane owns platform operators/configuration, account lifecycle, legal entity/property directory metadata, platform catalogue, subscriptions/licences, billing references, entitlements, support grant lifecycle, platform audit, minimized usage and health aggregates. It does not own client reservations, guest profiles, itemized POS checks, stock quantities, or journal lines.

**Onboarding:** operator records account and administrator invitation; administrator verifies access and completes profile; legal entities/properties/outlets are registered or delegated; plan/subscription is selected; signed entitlement is issued to tenant runtime; every action is audited. Require explicit confirmation/approval for high-impact suspension and ownership changes.

Control-panel surfaces: account search/status; property and deployment placement; subscription/renewal/invoice status; licence and entitlements; platform staff/roles; aggregate usage/system health; support request/grant/revoke; audit/security alerts. Use explicit application services, not a control-panel DB user with access to operational tenant tables. Support views must execute in the target tenant's authorized context, show a visible support banner, and constrain scope.

For SaaS, control plane may reside in a logically and credential-separated database/schema. On-premise receives signed account, licence, configuration and update packages; returns minimized data subject to customer contract and connectivity. Operational data stays local unless customer explicitly enables a named integration or backup.

## 4. PostgreSQL RLS and tenant isolation

**Proposed launch topology:** separate control-plane and tenant-data-plane databases/credentials, even if on one PostgreSQL cluster. Shared tenant DB holds multiple accounts. Every tenant table has non-null `account_id`; property/outlet rows have their scope and account consistency constraints. Dedicated DB placement is supported through a tenant registry and connection resolver.

### RLS policy pattern

- Enable and `FORCE ROW LEVEL SECURITY` on tenant tables, including partition children; absent context means default deny.
- Runtime role is non-owner, non-superuser, has no `BYPASSRLS`, and cannot migrate or change policies. Use separate tightly controlled migration role.
- Resolve membership in trusted application code. Within a transaction, establish transaction-local `app.account_id`, principal, and authorized scope. All SQL executes before transaction end. Never use session-persistent tenant context; safely reset pooled connections.
- RLS `USING` and `WITH CHECK` constrain visibility and writes. Property/outlet access is also enforced through policies/scoped data access and application policies. Composite constraints prevent cross-account parent references.
- Queue consumers re-resolve current membership and entitlement, establish context per transaction, and fail closed. Do not trust tenant IDs in messages without revalidation.
- Isolation tests use the non-owner runtime role and cover no context, cross-account read/write, spoofed parent IDs, pooled-connection reuse, raw queries, exports, and jobs. CI checks each tenant table has forced RLS and policy coverage.

A custom PostgreSQL GUC propagates context and guards against accidental unscoped queries; by itself it is not protection against arbitrary SQL under compromised app credentials, because the role may set a custom GUC. Safe query construction, least privilege, credential isolation, network restrictions and monitoring remain necessary. Higher assurance uses per-tenant credentials/dedicated databases and ultimately separate clusters. Shared-RLS does not claim to withstand a fully compromised application DB credential.

`TenantPlacement(account_id, database_shard, region, schema_version, status)` is control-plane metadata. All access goes through tenant-aware connection resolution. Tenant moves use maintenance mode, consistent snapshot, change/outbox catch-up, restore/validation, atomic placement switch, count/checksum and policy validation, then resume. Never hardcode account-to-database routing.

## 5. Identity, authorization and support access

Platform and client principals use distinct guards/session scopes, role namespaces, MFA requirements and audit actor types. Platform roles cannot satisfy client policies. Client users have account membership and optional property/outlet roles. Authorization combines RBAC, resource policy, tenant context, record state and entitlement. Enforce on reads/writes, APIs, exports, attachments, jobs, reports and websocket subscriptions.

Default platform access covers account metadata, subscription/licence and aggregated usage, not guest or itemized operational/financial records. Silent impersonation is prohibited.

**Support grant state machine:** `requested → approved → active → expired/revoked/denied`. Requester supplies account, purpose/ticket, resource scope, permissions and duration. Client account administrator approval is preferred. No emergency access until explicit policy approval; if adopted, use two-person/security approval, MFA, alerts, narrow read-only scope, short TTL and retrospective review. Grant is bound to requester, expires automatically, can be revoked, and cannot grant platform-user or billing-credential administration. Show active access to client. Attribute every session and sensitive action/export; where row-level logs are too voluminous, record a tamper-evident access session and all mutations/exports. No approval means no access.

## 6. Financial ledger and inventory movement

### Financial ledger

Use double-entry GL per legal entity and accounting period. `JournalEntry` includes immutable ID, source type/ID, idempotency key, posting time, accounting date, currency, status, actor/system and reversal link. `JournalLine` includes account, debit/credit, currency, legal entity and optional property/outlet/cost center. Posted entries balance per currency/entity; enforce in posting service and deferred DB validation/controlled posting procedure. Drafts can change; posted journals cannot update/delete. Correct with linked reversal and replacement. Period close/reopen is permissioned, reasoned and audited. FX records source/rate/time/policy. Subledger sources link to journal IDs; outbox emits `JournalPosted` only after commit.

### Inventory movement ledger

Inventory is an append-only movement ledger, not mutable `quantity_on_hand` truth. Movement records item, account/property/outlet, stock location, lot/expiry if used, signed base-unit quantity, source document/line, effective/recorded times, actor, idempotency key and valuation reference. Receipts, transfers, issues/consumption, returns, counts, waste and corrections create movements. On-hand and valuation are rebuildable projections. Count variance creates approved adjustment; never edit history. Costing (weighted average/FIFO), recipe depletion timing, negative-stock policy and accounting integration need product/accounting approval. Both ledgers need compensating entries, source references and reconciliation reports.

## 7. PMS, POS, procurement, accounting and channel blueprints

These are architectural blueprints only; this task does not authorize implementation.

### PMS

Reservation flow: quote/hold → confirmed → checked-in/stay → checked-out → closed, with cancellation/no-show/amendment as explicit transitions and history. Model reservation, guest/occupant links with minimized PII, room-type inventory, room assignment, rate/fees/taxes, stay, folio, charges and payment-provider references. Availability is property/date scoped; prevent overbooking via transactional allocation/locks or dedicated allocation service. Folio settlement reconciles to accounting entries.

### POS

Outlet-scoped terminals, menus, versioned prices/taxes, checks/orders, lines/modifiers, tenders, discounts, voids/refunds and closeout. Posted/paid checks are immutable; later changes use authorized reasoned void/refund/adjustment events. Use idempotency keys and terminal sequence IDs. Offline sales use unique IDs and local sequences; tender references never contain card data. Recipe depletion occurs at configured business event (send/close); voids create compensating movements as policy permits.

### Procurement

Supplier catalogue → requisition/approval → purchase order → receipt/inspection → supplier invoice match → AP liability/payment reference. Three-way match PO, receipt and invoice; configure tolerances, approvals, tax and legal entity. Receipt creates stock movements; invoice approval creates AP journal. Detect duplicate invoices and delivery retries by supplier/account-scoped idempotency.

### Accounting

Chart of accounts, periods, tax mappings, dimensions, journal workflow, subledger posting, AP/AR references, reconciliation, close and statements. Map module events through versioned accounting rules. Entries are legal-entity-specific, balanced, immutable after posting and traceable. External accounting exchange is adapter-based and reconciled, never a second untracked ledger.

### Channel management

Adapters maintain external property/room/rate/availability mappings and encrypted credentials. Outbound updates are versioned/retryable; inbound reservations/cancellations deduplicate by channel/property/external ID. Retain minimal external payload and redact PII. Reconcile drift and show failed sync queue. Define one availability authority and channel-specific allotment/race policy.

## 8. Integration and transactional outbox

Write domain state and `outbox_messages` in the same database transaction. Dispatcher publishes at least once, retries with exponential backoff/jitter, and sends exhausted work to a dead-letter queue with operator replay. Consumers persist `inbox_messages`/dedupe key in the same transaction as their side effect. Promise at-least-once delivery, not end-to-end exactly once. Use provider idempotency keys, timeouts, circuit breakers, bounded retries and reconciliation for ambiguous outcomes. Verify webhook signatures/timestamps; persist and deduplicate before acknowledgement; process asynchronously. Envelopes include schema version, account, source, correlation/causation IDs, occurred time and PII classification; exclude unnecessary personal/financial payload. Never publish before commit.

## 9. Cloud and on-premise deployment

### Cloud SaaS

Stateless Laravel web/API replicas behind TLS ingress; independent queue/scheduler workers; PostgreSQL HA with RLS, backup and PITR; Redis for cache/queue with durability/HA monitored; private object store; secrets manager/KMS; centralized redacted telemetry/alerts. Keep DB and management planes private. Region/residency is deployment placement, not a UI filter.

### On-premise

Signed, versioned bundle with shared application code, PostgreSQL, Redis, reverse proxy/TLS, local object storage or supported S3-compatible target, and health/backup/restore tooling. Docker Compose may suit small sites; larger sites may use customer-managed orchestration. Include offline install/update packages, migration preflight, forward-fix/rollback policy, sizing, monitoring export, and support bundles that omit guest/financial payload by default. Property operations continue during WAN/control-plane outage.

### Shared core

Use adapters and deployment profiles, not product forks. Gate optional functions by signed entitlements. Validate supported PostgreSQL versions, backup/restore, queue, storage, timezone, and offline behavior. Central control plane never remotely queries local operational tables.

## 10. Offline operations and synchronization

Offline support is workflow-specific. Recommended on-premise edge is local PostgreSQL and shared app at property/site. Browser/POS devices cache only minimum authorized catalogue/config and queue uniquely identified commands; avoid broad guest PII caching. General cloud-browser offline support is later and needs encrypted storage, device logout and wipe requirements.

Events carry UUIDv7/ULID, device/site, monotonic local sequence, actor, schema version, occurred and received times. Sync is at-least-once; server inbox deduplicates. Do not treat client wall-clock as ordering authority. Property has one authoritative local writer for offline room/stock allocation. To allow channel sales while disconnected, reserve bounded channel allotments or suspend them; do not assume unconstrained multi-master inventory. POS/cash/tender events are append-only and reconcile on reconnect; never silently merge conflicting payments. Reconnect uses cursor batches, commit acknowledgements, conflict/reject queue, outbox replay and totals comparison. Financial posting remains tied to policy and is reconciled, not rewritten. Chairman must set offline maximum, licence grace, conflict ownership and operating procedures.

## 11. Subscription, licensing and entitlement

Separate commercial subscription, invoice/payment, and runtime licence state machines. Plan defines features/limits; subscription defines term/renewal/cancellation/billing; licence defines activation/suspension/expiry and signed offline entitlement; usage aggregates support metering; overrides have approver/reason/expiry. Centralize feature checks across UI, API, jobs and offline runtime.

Control plane issues signed entitlement manifests containing account/install ID, features/limits, validity window, sequence/version and key ID. Tenant verifies with public key; private signing key stays in KMS. Revocation propagates when connected; disconnected installation uses a Chairman-approved lease/grace period and advance warning. Suspension must not destroy data, block export, or block restore/safety operations. Payment integration retains provider references, never card PAN. Grace, suspension, reinstatement, tax invoices and lease periods need Chairman decisions.

## 12. Security, audit and data protection

Threat model platform-admin compromise, cross-tenant IDOR, SQL injection/RLS-context spoofing, credential theft, support insider, malicious integration/webhook, lost offline device, ransomware, supply-chain attack and tenant migration error. Require MFA for platform staff; least privilege; scoped service accounts; secret manager/rotation; TLS/encryption at rest/key separation; secure headers, CSRF/CORS and rate limits; input/output validation; signed webhooks; dependency scanning/SBOM/patching; private DB/network; secure backups and incident response.

Audit privileged account/role/licence/subscription/support changes, exports, financial post/reversal, stock adjustment and security events with actor, target, scope, time, result, reason and correlation ID. Exclude secrets and unnecessary PII. Protect tamper-evident audit retention from routine changes and restrict access to logs. Define privacy notice, processor terms, lawful basis/retention, export/deletion, residency and breach notification before launch. Guest retention must account for statutory holds and privacy rules. Delegate card handling to certified provider. No credentials in repository/logs.

## 13. Backup, recovery and scalability

Cloud PostgreSQL uses encrypted base backups plus WAL/PITR, periodic restore drills, isolated backup credentials/keys, immutable/offsite copies, and tenant-scoped logical export. On-premise automates encrypted local backup with optional customer-approved offsite replication. A restore package covers DB, object assets, config without plaintext secrets, key-recovery procedure and relevant control-plane metadata. Control plane and tenant data plane have separate recovery procedures. Shared-db single-tenant restore uses point-in-time clone to an isolated instance, tenant-scoped export/import with referential validation and audit/outbox reconciliation; never roll back all customers to restore one tenant. Define consistent semantics for financial data and external side effects.

Set tiered RPO/RTO and retention by deployment and customer contract; none is assumed here. Conduct restore drills, including one-tenant restore and on-premise recovery. A tenant move uses consistent snapshot, outbox/change catch-up, validation, atomic placement switch and rollback plan.

Scalability: indexes should lead with `account_id` for tenant query patterns and include property/date dimensions as measured. Partition high-volume append-only tables (audit, POS lines, folio postings, channel updates) when volume warrants, with retention/partition lifecycle. Use scoped read replicas/materialized reporting projections, per-account rate limits and queue fairness/quotas. Monitor DB connections, latency, locks, queue age/dead letters, outbox/sync lag, disk, backup age, tenant hot spots. Pooling must preserve transaction-local RLS. Large tenants move to dedicated DB/region via placement registry, with no cross-account foreign keys or shared sequential IDs. Do not partition/index speculatively without measured query and growth targets.

## 14. Technology decision register

| ID | Decision | Status | Rationale / trade-off |
| --- | --- | --- | --- |
| T-01 | Laravel/PHP | Approved foundation principle; exact supported version proposed at implementation | Mature application base; select release compatible with customer environments. |
| T-02 | Filament central admin | Proposed | Fits internal control surfaces; validate customization and support workflows. |
| T-03 | Vue client UI | Proposed only where justified; otherwise open | Avoid dual-frontend cost; decide after client workflow prototype. |
| T-04 | PostgreSQL | Approved foundation principle | Transactions, relational integrity, RLS and mature recovery. |
| T-05 | Modular monolith | Approved | Explicit boundaries in one deployment; no premature services. |
| T-06 | Separate control/data-plane DB roles and connections | Proposed | Platform has no grants on operational tables; client pushes minimized aggregates to control plane. |
| T-07 | Shared tenant DB with forced RLS | Approved principle; proposed first topology | Database-enforced boundary, later dedicated tenant DB/region. Shared credentials cannot withstand full app compromise. |
| T-08 | UUIDv7 primary/public keys | Proposed, pending Chairman/KAB decision | Globally unique, approximately time ordered; suitable for offline creation and tenant moves. Evaluate library/runtime support before lock-in. |
| T-09 | Redis for cache/queue | Proposed from strategic baseline | Not ledger of record; verify queue durability/HA and on-premise fit. |
| T-10 | Docker packaging | Proposed | Repeatable cloud/on-prem installs; compose vs orchestrator by site size remains open. |
| T-11 | REST `/api/v1`, outbox/inbox | Proposed | Interoperability and reliable at-least-once async delivery. Decide before first business module. |
| T-12 | Signed offline entitlements | Proposed | Local validation without WAN; lease/clock-tamper policy needs decision. |
| T-13 | Immutable accounting and stock ledgers | Approved principle | Auditable reconstruction and compensating corrections; accounting policy remains open. |

Cloud provider/regions, framework versions, identity provider, payment/channel/fiscal vendors, queue driver, telemetry and object-store products are implementation-stage choices. No dependencies are installed in this task.

## 15. Claude findings resolution matrix

Claude's supplied review contained Critical C1–C2, High H1–H6, Medium M1–M7, Low L1–L6. The source text is the review attached for Task 004. Resolution is against this master architecture; “accepted” describes architecture treatment, not independent KAB approval.

| Finding | Resolution in this architecture | Status / remaining action |
| --- | --- | --- |
| C1 platform/client database separation | §3–4 separates DB roles/connections; platform has no operational grants; client pushes minimized aggregates; RLS forced; migration role separate; CI policy/table checks. | Accepted. Define exact CI static-analysis method and verify with runtime-role tests in Phase 2. |
| C2 on-prem/cloud/offline | §9–10 defines shared-code single-tenant on-prem, cloud shared tenant topology, signed offline licence, outbound minimized heartbeat, upgrades, local operation and conflict rules. | Accepted as proposed architecture direction. ADR-004/ADR-007 and lease/clock policy await Chairman decision; required proof remains gated. |
| H1 support session | §5 specifies grant-bound support principal, scope incl. optional property, client policy evaluation, step-up MFA, auto-expiry, dual identity audit, sensitive reads, fallback two Dawineks approvers + client notification. | Accepted with break-glass fallback requiring Chairman approval. |
| H2 audit integrity | §12 requires tamper-evident audit, role revocation of update/delete, separate write-once sink/hash chain consideration, monthly partitioning as scale/retention policy, client-visible access events; platform events may lack account. | Accepted. Select hash-chain vs immutable external store, partition retention, and proof scheme before implementation. |
| H3 hierarchy/outlets | §2 defines Account→LegalEntity→Property→Outlet, tenant key at account, legal/tax context, ownership/operator and memberships. | Accepted. Fiscal numbering and jurisdiction rules need D-01/D-06 decisions. |
| H4 entitlements safe degradation | §11 separates state machines, central entitlement service, signed lease, cross-surface checks; restricted state preserves check-out, settlement, reports, export. | Accepted. Final state transitions, grace, plan metric and exact restricted capabilities need Chairman decision. |
| H5 client session/device | §5 and §7 define server-bound account/property scope, token audience/scope, MFA client admins, outlet device principals, quick PIN staff switch/shift-bound session as POS requirements. | Accepted as requirements. Session/provider/device policy to define in Identity design. |
| H6 privacy compliance | §12 requires controller/processor, jurisdictional counsel, region, sensitive-ID field encryption, retention/deletion/legal hold, breach and cross-border controls. | Accepted. Kenya ODPC and each market's obligations require counsel sign-off before guest data. |
| M1 scale | §13 covers composite indexes, partitions, scoped read models, rate limits, queue fairness and DB move. | Accepted; partition and capacity thresholds deferred to measured workload. |
| M2 primary key | §14 proposes UUIDv7 for global/offline/tenant-move IDs. | Proposed, KAB approval/toolchain validation pending. |
| M3 integrations | §8 defines outbox/inbox, per-account connections/vault, external mapping, idempotent webhooks, versioned API. | Accepted; vendors (eTIMS, M-Pesa, channels, locks/accounting) deferred. |
| M4 job context | §4 requires account-bearing messages, membership/entitlement re-resolution, transaction-local context and explicit tenant iteration. | Accepted; CI tests before Phase 2 exit. |
| M5 control-panel exposure | §3 and §12 require separate control subdomain/session/cookies, restricted network path (VPN/zero-trust/IP policy), WebAuthn/FIDO2 preferred platform MFA. | Accepted; deployment/network profile and break-glass policy need decision. |
| M6 fiscal numbering | §2, §6 and §14 require jurisdiction, per-legal-entity/property timezone/currency/tax config, controlled gap-free fiscal sequences where law demands. | Accepted requirement; exact law/vendor process requires counsel and market scope. |
| M7 tenant restore | §13 provides isolated point-in-time clone and tenant export/import, validation/reconciliation and drills; RPO/RTO explicit decisions. | Accepted; operational tool/runbook and target values deferred to Phase 1/4. |
| L1 governance | Consolidated ADR-017 is Proposed and historical ADR-001 is Superseded; no ratification is recorded. This master is authoritative pending decision. | Accepted; ADR state normalized to Proposed pending Chairman decision. |
| L2 role namespace | §5 separates principal domains; schema uses separate platform/client role tables or constrained domain plus policy tests. | Accepted; concrete schema selection deferred. |
| L3 platform lifecycle | §12 includes MFA, offboarding, access review, sealed break-glass, session timeout as requirements. | Accepted; operational control procedure to define. |
| L4 observability | §12 requires tenant-tagged telemetry with PII scrubbing from logs/traces/error tools. | Accepted; implement shared redaction controls. |
| L5 NFR targets | §13 identifies monitoring and calls for explicit latency/capacity and uptime targets. | Open Chairman/product decision before pilot/SLA. |
| L6 billing details | §11 separates billing/licence; D-10 captures proration, dunning, M-Pesa matching, Dawineks VAT/eTIMS. | Deferred decision before billing implementation; fiscal compliance cannot be deferred past applicable law. |

## 16. Gemini findings resolution matrix

The supplied Gemini audit is titled “Independent Enterprise Architecture & Technical Audit” and recommends REJECT pending redesign. Its findings are evaluated individually below. Acceptance of a finding does not mean adoption of the proposed implementation mechanism; dispositions are against the scope and approved principles of this master. No business module is implemented here.

| Gemini finding / recommendation | Disposition and architecture response | Follow-up |
| --- | --- | --- |
| Shared-DB cross-tenant risk; add RLS/sharding | **Accept risk; accept RLS and dedicated DB placement.** §§3–4, 13 define separate platform/data roles, forced RLS, default-deny, and account placement/migration. | Security test and KAB approval before Phase 2 exit. |
| Enterprise schema-per-tenant / DB isolation | **Modify.** Launch shared RLS; support dedicated database/region for enterprise. Do not choose schema-per-tenant as a third mode until evidence justifies its migration/ops complexity. | Validate tenant placement and residency requirements with first enterprise contracts. |
| Cross-property stock transfer breaks tenant boundary | **Reject premise.** Properties/outlets/stores can transfer within one Account using explicit source/destination scope and ledger movements; inter-account transfers are separate commercial transactions with bilateral authorization and accounting. | Define inter-legal-entity transfer/tax rules in procurement/accounting design. |
| Event sourcing/CQRS for all core finance/stock | **Modify.** Keep operational state transactionally consistent in PostgreSQL; financial and inventory ledgers are append-only, and projections are rebuildable. Use outbox/inbox for asynchronous integrations. Do not event-source every aggregate or make ledger projections eventual where users need immediate authoritative balances. | Per-module transaction and projection consistency design before coding. |
| Redis Redlock/distributed locks for inventory | **Reject as default.** Use PostgreSQL transactional allocation, unique/conditional constraints, row/advisory locks, and idempotency within one authoritative database. Redis Redlock is not required for DB transactions and creates another consistency dependency. | Load-test contention and choose exact isolation/locking strategy for reservation and stock allocation. |
| Hierarchical MDM and global guest profile | **Accept hierarchical configuration need; modify implementation.** Model account/group defaults, legal-entity/property/outlet overrides as versioned, explicit configuration with provenance and audit. Keep guest identity consolidation as a later consent/privacy-governed capability, not foundational global PII sharing. | Define inheritance/override conflict rules; privacy/legal approval before guest profile deduplication. |
| Entitlement policy engine / OPA | **Accept centralized entitlement decision; defer OPA.** Use one application entitlement service and signed offline manifest. OPA adds policy distribution/operations; introduce only if policy complexity or independent policy governance warrants it. | Product confirms feature/room/outlet/API usage meters and cache invalidation before billing build. |
| Multi-country tax, currency and legal-entity boundaries | **Accept.** Legal entity owns tax identity/books/currency; property owns local timezone/operating settings; versioned tax and FX rules are explicit. | Counsel/accounting confirm jurisdiction requirements and fiscal invoice numbering. |
| PCI scope / payment handling | **Accept.** Delegate card capture to certified provider SDK/hosted fields/terminal; raw PAN/CVV never touches Dawineks services or logs. Retain payment tokens/references only. | Provider/acquirer validates PCI responsibilities and terminal architecture. |
| Fine-grained SoD and sensitive PII access | **Accept.** Add permission-level SoD constraints (e.g. initiator cannot authorize own void/day-close), step-up approval, masking and audited high-risk reads. RBAC plus contextual policy is preferred; ABAC where attributes are needed. | Define job-role matrix and review controls per finance/fiscal operation. |
| Synchronous integrations and outage handling | **Accept.** Outbox-driven at-least-once adapters, timeout/circuit-breaker/retry, idempotency and reconciliation; user-critical local workflows do not block on nonessential provider calls. Fiscalization/payment legal requirements define which actions require provider response before completion. | Provider-specific failure and pending-state contracts before integration modules. |
| Kafka/RabbitMQ integration bus and microservices | **Reject initially.** Transactional outbox plus existing queue is enough for a modular monolith. No Kafka, RabbitMQ, or integration microservices until throughput, replay, fanout or deployment isolation data justify them. | Reassess from measured backlog/latency/availability requirements. |
| Edge node and CRDT / bidirectional replication | **Modify.** Shared application + local PostgreSQL on-prem; append-only ID-addressed sync, inbox/outbox, deterministic per-aggregate authority and bounded allotments. Avoid general CRDTs: reservations, money and stock have business invariants that require explicit conflict handling. | Decide local authority, channel allotments, offline window and hardware profile. |
| PMS gap list (yield, housekeeping, night audit) | **Accept as future domain scope, not a current foundation defect.** The PMS blueprint establishes reservation/capacity/stay/folio boundaries; yield management, housekeeping dispatch and night audit become backlog/requirements for a later PMS vertical slice. | Product selects initial PMS scope and acceptance criteria. |
| POS gap list (split bill, KOT/BOT, multi-kitchen, offline) | **Accept as future domain requirements.** POS blueprint includes persistent checks, split tenders, terminal identity, kitchen routing and offline replay requirements. | Discover outlet workflows before POS implementation. |
| Inventory BOM, waste, valuation, multiple warehouses | **Accept as later inventory requirements.** Movement ledger supports stock locations, recipe consumption, waste and cost layers; specify BOM, actual/theoretical variance and costing policy in Inventory phase. | Accounting and operations approve costing/depletion policy. |
| Procurement, supplier portal, reorder, GRN | **Accept as later product scope.** Procurement blueprint includes approvals, purchase order, receipt/inspection, three-way invoice match; supplier portal and reorder automation are deferred features. | Select supplier integration and approval policy later. |
| GL, AR/AP, trial balance | **Accept as later accounting scope.** Master defines double-entry GL and procurement/AP posting. Full AR/AP, statements, close and trial balance are not implemented or claimed complete. | Accounting owner defines chart, source mappings and close requirements. |
| OTA XML/JSON, rate parity, overbooking | **Accept as later integration scope.** Channel blueprint defines mapping, two-way availability/rates, idempotency and reconciliation; specific OTA protocols and parity controls are adapter requirements. | Select channel manager/provider and inventory authority rules. |
| Scope concern: architecture weak for enterprise | **Partially accept.** This master adds group/entity hierarchy, tenant placement, audit/ledger, offline and integration designs. Performance/compliance/operational targets remain to be set and proven; no architecture document alone proves enterprise readiness. | Review D-01 through D-14, threat model, and pilot NFR evidence. |

### Rejected implementation prescriptions

The audit's risk identification is useful; several prescriptions are not adopted as defaults. Generic CRDT merging does not preserve room, tender, or accounting invariants. Redis Redlock is not the source of truth for transactional PostgreSQL data. Kafka/RabbitMQ and separate integration microservices add operational burden before scale is demonstrated. Use bounded transactional concurrency, immutable ledgers, outbox/inbox, and workflow-specific conflict rules first; revisit with measured evidence.

## 17. Outstanding risks and historical decision register

**Governance note:** The D-01–D-15 entries below are retained from an earlier master revision and are historical input, not the current decision register. They are superseded for tracking purposes by current C-01–C-13 in §27. All current Chairman decisions are Proposed and awaiting decision.

| ID | Decision / risk | Recommended default | Decision gate |
| --- | --- | --- | --- |
| D-01 | Account/group, legal entity, property ownership/operator relationships; jurisdictions | Account is tenant/commercial root; legal entity is books/tax root; property has owner plus optional operator; outlet is property child. | Before schema design and contracts. |
| D-02 | Support approval when no client admin is available | No exception by default. If needed: two Dawineks approvers, step-up MFA, short read-only grant, immediate client notification, retrospective review. | Before support access build. |
| D-03 | Cloud hosting region, residency and placement tier | One approved region; separate control/data DB roles; shared RLS tenant DB initially, dedicated tenant DB/region by contract/scale. | Before production/customer commitments. |
| D-04 | Offline authority, max disconnect, sync conflict and clock tamper | Local site writer; bounded channel allotments; signed entitlement lease with rollback-resistant monotonic/secure time where feasible; reconcile conflicts explicitly. | Before PMS/POS/channel work. |
| D-05 | Licence state transitions and safe restriction | `active → grace → restricted → suspended`; restricted preserves in-house guest checkout, folio settlement, open POS completion, reports, financial close/reconciliation, data export and recovery; never delete/block restore. | Before commercial launch. Define exact actions and grace durations. |
| D-06 | Accounting, tax, FX, stock valuation and fiscal numbering | Per legal entity chart/tax identity/books; jurisdiction-specific fiscal sequence and certified fiscal provider where required. | Before accounting/procurement/fiscal module. |
| D-07 | RPO/RTO, backup retention, uptime, latency/capacity NFR | Set tiered targets by SaaS/on-prem profile; test with representative property workload. | Before production SLA/pilot. |
| D-08 | Guest PII, ID/passport image, controller/processor and privacy regime | Client controller/Dawineks processor as contractually determined; encrypt sensitive fields/files; minimize; market-by-market counsel review. | Before any guest data collection. |
| D-09 | Client UI and platform admin network topology | Filament candidate; distinct control subdomain/cookies, restricted network/zero-trust, WebAuthn platform MFA. | During foundation build, before staff production access. |
| D-10 | Subscription billing providers and country fiscal obligations | Keep provider adapters; manual invoice is acceptable only if confirmed commercially and legally. | Before subscription billing release. |
| D-11 | Identity provider, client MFA and device policy | Platform phishing-resistant MFA mandatory; client admins MFA mandatory; device principal per POS terminal, PIN staff switch with shift timeout. | Before identity/device implementation. |
| D-12 | Claude/Gemini source findings and disposition approval | Review matrices record the sources provided and proposed dispositions; KAB approves conflicts and policy-changing choices. | KAB review of this master. |
| D-13 | High assurance tenant isolation requirement | Shared RLS protects against app mistakes, not compromised app credentials; offer dedicated DB/credentials for high assurance and region needs. | Customer security tiers/contract. |
| D-14 | Global master data and guest profile sharing | Versioned hierarchical operational configuration; no global guest PII consolidation until consent, purpose, legal basis and access controls approved. | Before corporate MDM/CRM features. |
| D-15 | Vendor/protocol commitments (payments, M-Pesa, eTIMS/EFD, channels, locks) | Adapter interfaces first; no vendor-specific core coupling. | Before corresponding integration. |

### Binding architecture clarifications

- **Usage telemetry:** tenant runtime computes minimized aggregates locally and pushes signed/idempotent usage batches outward. The control plane never queries tenant operational tables to derive usage. Heartbeat is outbound-only from on-premise; do not open inbound database/admin tunnels.
- **Support principal:** a support session is a distinct principal carrying `grant_id`, requester and approver identities, account and optional property scope, permission set, expiry and step-up-MFA time. Evaluate it through client policies with grant permissions only. Revoke session immediately on grant revoke/expiry. Record both support identity and affected actor/context. Log reads of guest/financial records and every export/mutation. Two-person emergency path and post-event client notice need D-02 approval.
- **Audit integrity:** app roles have no UPDATE/DELETE on audit records. Use hash-chained events per account plus external immutable/WORM sink for platform/security events; include nullable account for platform-only events. Partition by month with retention/legal hold. Let client admins read relevant access/admin audit events under tenant RLS.
- **Entitlement enforcement:** one entitlement service and signed manifest; evaluate at middleware/use-case boundaries, including background work. Cache effective policy only with version/expiry and invalidation. Meters must identify account/property/outlet (rooms, outlets, feature tiers, optional API/channel usage). Restricted state permits safe continuity and exit tasks listed in D-05; denial of new arrivals/new sales can be graduated only after safe-state policy and notice.
- **Identity and SoD:** server binds active account/property/outlet to session/token claims and revalidates membership. API tokens have audience, scopes, expiry and rotation. Client admin MFA is mandatory; offer MFA to all users. POS terminal is an outlet device principal; staff PIN switch is quick convenience layered on trusted device identity, with shift-bound sessions and reauthentication for high-risk operations. Enforce initiator/approver separation for voids, comps, refunds, stock adjustment, period close and day-close settlement where configured.
- **Sensitive data:** encrypt high-risk fields and ID/passport images with separately managed keys; strictly authorize/decrypt on demand and audit reads. Payment capture uses provider-hosted fields or certified terminal SDK so raw card data never reaches Dawineks servers. Apply controller/processor terms, jurisdictional residency and retention, data-subject erasure with fiscal/legal holds, breach process and cross-border review. Counsel must confirm Kenya Data Protection Act/ODPC and each target-market duties; this document is not legal advice.
- **Configuration hierarchy:** use versioned templates/values with `source_level`, effective date, override reason and audit. Precedence is account/group default → legal entity → property → outlet. Global menu/chart/tax templates never overwrite local data silently. Guest profile consolidation is not part of baseline MDM.
- **Fiscal and scale controls:** define timezone/currency/tax per legal entity and property. Fiscal document sequences are scoped to legally required entity/site/series, gap handling is explicit and audit-safe. Tenant queries/indexes lead with account; apply queue fairness/rate limits; isolate large tenant by placement, avoid shared sequences and cross-account FKs.
- **NFR and operations:** set availability, p95 latency for front-desk/POS, throughput, recovery and peak occupancy/service workload before pilot. Telemetry carries account tag only where authorized; scrub PII from traces/error tools. Platform users require joiner/mover/leaver flow, periodic access review, short sessions and sealed break-glass identity.

## 18. Phased development roadmap

**Authorization boundary:** The phases below are a planning sequence only. No phase or application development is authorized by this document. Start work only after the Chairman separately authorizes a defined scope and applicable gates are accepted.

**Phase 0 — Architecture approval:** KAB/Chairman reviews this master and the two source matrices; resolves D-01–D-15 as needed; confirms initial customer/deployment profile and first business workflow. No PMS module in this task.

**Phase 1 — Engineering foundation:** choose supported PHP/Laravel/PostgreSQL versions and runtime; create modular skeleton, environment template, CI/static analysis, secret management, cloud/on-prem packaging, observability, migration conventions and developer setup. Define separate platform/client connections and DB roles before tenant tables.

**Phase 2 — Security and tenancy core:** implement principal separation, account/legal-entity/property/outlet directory, memberships and scoped policies, forced RLS, runtime/migration roles, support-session type, audit integrity, tenant placement registry and adversarial tests as non-owner role. Verify control-plane role cannot access operational tables; verify control-plane aggregation is push-only. Security review gate.

**Phase 3 — Central Control Panel:** onboarding, platform user lifecycle/MFA, plans/subscription/licence transitions, entitlement service and signed offline manifest, billing references, pushed aggregate usage/health, support workflow, audit and restricted control-plane network exposure. No guest data until privacy/legal controls approved.

**Phase 4 — Shared operational foundations:** transactional outbox/inbox and integration secret vault, idempotency, webhook framework, file isolation, tenant backup/restore and DB-move tooling, queue fairness, monitoring and signed release/update. Validate SaaS tenant and on-premise single-tenant profile; run backup/restore drills.

**Phase 5 — First business vertical slice (separate approval):** Chairman selects PMS, POS, procurement or another first scope. Implement one property/outlet workflow end-to-end with policy, audit, ledger where needed, outbox, tests and explicit offline/failure behavior. Do not attempt full suite in one release.

**Phase 6 — Commercial readiness and expansion:** security/privacy/counsel review, NFR/restore evidence, pilot and support processes. Expand to PMS/POS → inventory/procurement → accounting/channel integrations according to product dependencies. Move high-volume/regulated tenants to isolated DB/region when contract or measurement requires it.

**Exit gate before first operational module:** KAB accepts tenant boundary/data placement; cross-account and property denial is proven under runtime DB role; RLS cannot be bypassed by platform identity; support policy and audit are approved; backup/restore demonstrated; privacy controls approved before guest data; accounting/tax rules, offline limits, workflow owner and acceptance criteria documented.

### Integration credential and reconciliation clarifications

Per-account integration connections store secret-manager references rather than plaintext credentials in application tables. Keep provider secrets in a vault/KMS-backed secret store, rotate them independently, and never include them in logs or outbox payloads. Store external-ID mappings with account/provider/resource scope. Webhook idempotency keys are unique within the provider/account boundary.

### Review source provenance

The first attachment labeled Gemini Source duplicated the Claude Architecture Review in substance. A later Gemini Source attachment provided the distinct enterprise technical audit mapped in section 16. The duplicate is not counted as an additional independent finding set.

## 19. Final reconciliation addendum — runtime isolation and licensing

**Reported final review outcomes:** Claude Code — **APPROVE WITH CONDITIONS**; Gemini — **APPROVE WITH CONDITIONS**, as supplied for this review. The original final assessment letters remain unavailable; these outcomes are not presented as verbatim reviewer letters. Gemini’s earlier preliminary “REJECT” recommendation captured in §16 is historical and is superseded by the supplied final outcome. The mapped Task 005 conditions are architecture requirements, not evidence that implementation or verification has occurred.

### PostgreSQL connection pools and tenant context

All tenant queries execute inside a transaction whose context is established only after the authenticated principal's current account/property membership is revalidated. Set account and authorized scope using transaction-local settings (e.g. `set_config('app.account_id', value, true)` or equivalent `SET LOCAL`). Never set tenant identity at session scope. The transaction ends (commit or rollback) before a pooled connection returns to the pool, automatically discarding transaction-local context.

Pool checkout/check-in must also defend against leaked session state: rollback any open transaction; reset role, search path, timezone, statement settings and other mutable session configuration to the approved baseline; discard temporary objects where applicable; evict connections whose reset fails. Use pooler mode and reset behavior compatible with transaction-local context. Do not rely on session affinity. Application roles cannot change to a privileged role or bypass RLS. GUC context is a guard against unscoped application code, not protection from arbitrary SQL under a compromised runtime credential.

### Laravel tenant-context proof obligations

One tenant-context service owns validation and transaction binding; no component invents scope from a request parameter or serialized model. Context is request/job scoped, immutable during a transaction and cleared after use. Before any business module is approved, implement automated tests using the non-owner runtime DB role that prove the following:

- **Filament:** panel guard and tenant resolver bind active account/property from membership; resources, relation managers, global search, widgets, actions, exports, reports and bulk actions cannot bypass tenant policies/RLS. Platform Filament uses a distinct platform connection/role with no operational-table grants.
- **Livewire:** every initial render, hydration, action, upload and pagination request re-resolves identity and membership server-side. Component properties/URLs are untrusted; stale tenant IDs or tampered model IDs fail closed. Each request re-establishes a transaction-local DB context.
- **Queues and schedules:** jobs carry stable account/resource IDs and actor/system purpose, then re-resolve current membership, grant, licence and placement when executed. They establish context only for the job transaction and fail if missing/invalid. Scheduled tenant iteration is explicit and separately scoped for each account.
- **Octane/long-lived workers:** no tenant, user, authorization decision or model is held in a singleton/static mutable state. Request termination flushes scoped container state and DB transactions. Sequential alternating-tenant requests, exceptions, worker reuse and pool reuse must prove no context bleed.
- **Pooler and failure paths:** test commit, rollback, timeout, connection loss, retry, nested transactions, transaction-pooling mode, and reset failure. Any ambiguity evicts the connection and fails closed.

CI must include cross-account/property denial, stale membership, support-grant expiry, entitlement transitions, missing context, and RLS policy/table-coverage checks. Static conventions detect raw tenant-model access outside approved scoped repositories/services; allowlisted exceptions require review. These are release gates, not aspirational test suggestions.

### PostgreSQL support floor and views

Support PostgreSQL 15+ as the minimum tenant-data-plane version. Any view over tenant tables that is exposed to a runtime role must use `security_invoker = true` (PostgreSQL 15+) so base-table privileges are evaluated as the caller; add `security_barrier = true` where the view is an intentional row-filtering security boundary. A view does not replace base-table RLS, grants, policies, or tests. Test view behavior as the actual runtime role and verify underlying RLS applies. Do not use owner-privileged views to expose tenant data to the platform role. Keep app schemas, object owners and migration credentials separate from runtime roles. [PostgreSQL 15 view security options](https://www.postgresql.org/docs/15/sql-createview.html)

### Signed offline licence cache

Tenant runtime caches only a verified signed entitlement/validity manifest. It contains account and installation IDs, feature/limit claims, sequence/version, key ID, issued/not-before/expiry timestamps, maximum offline grace end, and permitted deployment profile. Sign with a control-plane private key held outside tenant installations; validate with pinned public keys and explicit key rotation/revocation. Bind it to installation identity to limit copying. Persist the highest accepted sequence and last trusted time; detect rollback/replay and clock rollback, alert Dawineks when connectivity returns, and fail closed for new privileged enablement when validity cannot be established beyond the approved grace. Ordinary operations during grace follow D-05 safe-continuity rules. The cache is not itself authority to read another tenant's data and does not contain secrets or guest data. Maximum lease/grace and clock-tamper response require Chairman approval.

### Authenticated tenant-to-control-plane telemetry

Telemetry is initiated outbound by the tenant installation. Authenticate installation using mutually authenticated TLS with per-installation credential rotation and/or signed payloads; validate installation/account binding, message signature, timestamp/nonce, monotonic sequence and replay/idempotency key. Accept only an allowlisted schema of minimized counters, version/health status and sync/licence state; no guest PII, operational records, financial lines, or free-form payloads. Control plane validates, deduplicates and stores aggregates in a platform-only ingestion store; it never reaches into tenant DBs to calculate usage. On-premise telemetry is opt-in/contract-governed, can queue locally while disconnected, and has explicit retention/deletion. No inbound management tunnel is required.

## 20. Final reconciliation addendum — ledgers, inventory, offboarding and launch profiles

### Automated financial ledger reconciliation

Financial controls run both synchronously at posting and asynchronously on scheduled cadence. At every posting, reject an unbalanced journal by legal entity and currency, duplicate source/idempotency key, closed period, invalid account, or mismatched account/property/legal-entity scope. Nightly (and at close) reconcile: source subledger totals to GL control accounts by period/entity/currency; POS tender and settlement totals to payment-provider/acquirer batches; M-Pesa/cash/bank receipts to clearing/bank records; tax/fiscal documents to posted revenue/tax lines; and reversals/refunds to originating documents. Compare counts and monetary totals, not just row existence.

Reconciliation runs are reproducible, versioned and idempotent, with source cut-off/cursor, totals, variances, completion status and correlation IDs. Differences create scoped exceptions assigned to an authorized finance role; resolution posts a correcting/reversal entry and never edits a posted entry. No silent plug or auto-write-off. Material/unresolved variances block period close according to a Chairman-approved tolerance policy. Reports expose both operational source and GL trace. External-system outages leave explicit pending/retry/reconciliation states.

### Database-enforced financial ledger immutability

Posted `journal_entries` and `journal_lines` are append-only at database level as well as application level:

- Runtime roles receive `INSERT` only through a narrowly granted posting procedure/service and `SELECT` as policy permits; revoke `UPDATE`, `DELETE`, `TRUNCATE`, ownership and DDL privileges on posted ledger tables.
- Separate table owner/migration role from runtime. Add database triggers that reject mutation/deletion of posted entries/lines and prevent status reversal from posted to draft. Corrections use new linked reversal/replacement rows.
- Posting procedure validates legal entity/account scope, idempotency, period state and balanced debit/credit totals by currency before atomically marking posted. Use a deferred constraint trigger or equivalent transaction-bound validation so partially inserted multi-line journals cannot commit as posted.
- Procedures use a fixed safe `search_path`, least privilege, explicit caller/context checks and audited execution. Any controlled maintenance/retention exception is break-glass, independently approved and captured in tamper-evident audit. DB superuser/migration access remains a privileged operational risk, not part of app capability.
- Add equivalent append-only controls for financial posting history and inventory movement rows. Test every mutation path as runtime and application-owner roles; verify correction flows and restore/replay.

### Inventory balance consistency and transactional locking

For each `(account, property, stock location, item, lot/condition)` maintain a balance projection for fast decisions, but the immutable movement ledger is the audit source. In one PostgreSQL transaction: lock affected balance rows in deterministic sorted key order with `SELECT ... FOR UPDATE` (or a tested conditional-update strategy); verify available/reserved quantity and policy; append idempotent movement rows; update/rebuildable balance projection; write the outbox event; commit together. A unique source/idempotency constraint prevents double receipt/depletion. The application must never update a balance without its movement, or movement without a corresponding projection update in the same transaction.

Concurrent reservation/issue/transfer operations serialize on the authoritative rows and enforce negative-stock/availability policy at commit. Same-database transfers debit and credit source/destination atomically with in-transit or paired movement references. Cross-database/site transfer is a saga: source dispatch, in-transit custody, destination receipt; it is not a distributed DB transaction. Inventory count/valuation reconciliation compares ledger-derived balance to projection and physical count, raises exceptions, and posts approved adjustment movements. Rebuild projections from movements and compare checksums/totals. Redis Redlock is not used as correctness authority.

### Tenant offboarding and cryptographic erasure

Offboarding is a controlled lifecycle, not a cascade delete: freeze new access and integrations; revoke sessions/devices/support grants and credentials; preserve customer-requested export; identify invoice, tax, fiscal and litigation retention holds; disable new writes; obtain authorized deletion approval; delete or anonymize eligible tenant records and blobs; revoke tenant encryption keys; retain only legally required minimum metadata; record completion evidence and tombstone the account ID against accidental reuse.

Where cryptographic erasure is required, encrypt tenant-sensitive payloads and files with per-account data-encryption keys (DEKs) wrapped by managed KMS keys. Destroy the account DEK and all recoverable wrapped copies after legal hold clearance, making encrypted payloads in backups/object versions unrecoverable. Database indexes, identifiers and non-sensitive metadata need explicit row deletion/anonymization; key destruction alone does not erase plaintext columns. Scrub/expire caches, search indexes, exports, telemetry, replicas and support bundles. Backups age out on defined retention; where encrypted payload remains, key destruction applies to backup copies too. Validate erasure via a tracked checklist without retaining erased PII. Any statutory retention exception must identify fields, basis, expiry and access restriction. Exact retention, legal hold and customer notice rules require counsel/Chairman decision.

### Explicit cloud and standalone on-premise launch boundaries

- **Cloud SaaS launch profile:** multi-account service managed by Dawineks, control plane plus shared tenant data plane, account RLS, approved region, central monitoring/backup, authenticated aggregate telemetry, and tenant-aware placement for future dedicated databases. No customer-side active-active copy is implied.
- **Standalone on-premise profile:** customer-controlled installation using the same core code, local PostgreSQL and local operations; one installation is isolated to one Account (which may contain multiple legal entities/properties/outlets). It can operate without WAN within the signed entitlement lease. Local operators administer local workspace. Dawineks Control Panel manages licence/account metadata only when authenticated outbound communication is available; it cannot query the local tenant database. Upgrade bundles are signed/versioned and installable offline with preflight, backup, migration, rollback/forward-fix and restore procedures.
- **Not in first launch unless separately approved:** continuously synchronized hybrid edge where cloud and site both write the same reservations, room allotments, stock or finance; transparent bi-directional database replication; active-active site failover; and unattended cross-site conflict resolution. These need a separate consistency/operations design. Offline operation inside a standalone site is in scope; cross-site cloud/edge multi-master is not.
- Cloud and standalone profiles share core domain code and versioned APIs, but have separate data placement, identity operations and control-plane connectivity assumptions. Each profile must independently pass security, backup/restore, upgrade and entitlement gates before being marketed as supported.

## 21. Updated review reconciliation matrix

The final disposition supplied for both reviewers is **APPROVE WITH CONDITIONS**. The detailed final assessment documents themselves were not attached; the condition set used here is the ten mandatory additions in Task 005, reconciled against the original Claude and Gemini sources already mapped in §§15–16. Do not attribute any additional unprovided wording to either reviewer.

| Task 005 condition | Architecture decision | Claude final condition mapping | Gemini final condition mapping | Verification gate |
| --- | --- | --- | --- | --- |
| PostgreSQL pool sanitization and tenant isolation | Transaction-local context, safe reset/eviction, no session tenant state, transaction-pool safe pattern | Resolves C1/M4; closes context persistence risk | Resolves shared-DB/RLS and split-brain context risk | Pool leakage/rollback/alternating-tenant tests under runtime role |
| Laravel Filament/Livewire/queues/Octane proof | Explicit context resolver and surface-by-surface proof obligations in §19 | Extends C1, H5, M4 | Extends RLS and platform/client boundary | Required CI suite passes before first business module |
| Offline signed licence cache | Signed manifest, offline lease, replay/clock rollback controls and safe continuity | Resolves C2/H4 | Resolves edge/offline and static entitlement risks | Signature/key rotation/offline/rollback tests; Chairman sets lease |
| Automated financial reconciliation | Posting invariants plus scheduled source-to-GL/payment/tax reconciliation, exceptions | Extends H4/M6/L6 | Resolves event-ledger/accounting completeness concerns | Reconciliation mismatch scenarios and period-close gate |
| DB-enforced ledger immutability | Runtime grants, triggers/procedures, deferred balance validation, reversals | Extends H2 | Resolves ledger tampering risk | Mutation denial and reversal tests as app/runtime roles |
| Inventory consistency and locking | Same-transaction movements/projection/outbox; row locks/constraints | Extends C1/M1/M4 | Resolves atomicity/Redlock concern without Redlock | Concurrent issue/transfer/duplicate tests and projection rebuild |
| Authenticated telemetry | Outbound-only mTLS/signed, sequenced, minimized tenant aggregates | Extends C1/C2 | Resolves central visibility for standalone installs | Forged/replay/cross-account payload denial; no operational query path |
| Tenant offboarding and crypto erasure | Freeze/export/legal hold/delete/key-destroy/tombstone lifecycle | Extends H6 | Resolves privacy/compliance and PII exposure | Restore/key-destruction and erasure evidence drill; counsel approval |
| PostgreSQL 15+ / invoker views | PG15 minimum; tenant views SECURITY INVOKER and tested under runtime RLS | Extends C1 | Resolves DB enforcement gap | Compatibility CI and view/RLS regression tests |
| Cloud vs standalone on-prem launch | SaaS shared RLS; standalone single-account local deployment; hybrid active-active deferred | Resolves C2 | Resolves edge-cloud boundary and split-brain risk | Profile-specific install, licence, backup, upgrade, recovery gates |

## 22. Claude final finding-resolution matrix

**Final disposition reported by user: APPROVE WITH CONDITIONS.** The original Claude findings remain itemized in §15. This matrix maps the final Task 005 conditions to the new resolution, rather than claiming the unprovided final assessment contained verbatim condition wording.

| Condition area | Final architecture resolution | Remaining evidence / gate |
| --- | --- | --- |
| C1: platform/client separation | Separate DB role/connection, platform no operational grants, push-only aggregate ingestion, forced RLS, sanitized pool and invoker views | Runtime-role and view tests; permission matrix review |
| C2: on-prem/offline | Standalone local profile, signed/cached licence, outbound telemetry, offline upgrade/restore, hybrid multi-writer excluded | Signed bundle/offline lease and restore drill; Chairman duration policy |
| H1/H2: support/audit | Grant-bound support principal, property scope, dual identity audit, read/export audit, append-only DB controls and tamper-evident sink | Approved emergency path, retention and audit-sink selection |
| H3/M2: hierarchy/IDs | Account→legal entity→property→outlet, proposed UUIDv7, property/account integrity | Approve ID implementation choice and fiscal numbering rules |
| H4: entitlement | Central service with active/grace/restricted/suspended states and safe operational exit; signed offline cache | Approve grace period, meter dimensions and restricted actions |
| H5: sessions/devices | Server-bound active scope; MFA, token audience/scope, terminal principal and shift-bound staff identity | Identity/device threat model and client MFA rollout policy |
| H6: privacy | Field/file encryption, controller/processor terms, hold-aware erasure and counsel review | Jurisdictional counsel sign-off before guest data |
| M1/M7: scale/recovery | Tenant placement, indexes/partition when measured, queue fairness, per-tenant restore and recovery drills | NFR/RPO/RTO targets and runbook evidence |
| M3/M4/M5: integrations/jobs/admin | Outbox/inbox, secrets vault, context revalidation, Laravel runtime proof, restricted control-panel network | CI gates and deployment hardening review |
| M6/L6: fiscal/billing | Per-entity rules and reconciliation, provider adapter, legal/fiscal billing decisions | Market, tax, eTIMS/M-Pesa and dunning scope decisions |
| L1–L5: governance/roles/ops | Proposed ADR status, role separation, platform lifecycle, sanitized observability, measurable NFRs | KAB approval, staffing runbooks and pilot targets |

## 23. Gemini final finding-resolution matrix

**Final disposition reported by user: APPROVE WITH CONDITIONS.** The original Gemini enterprise audit and its specific accepted/modified/rejected prescriptions remain in §16. Its earlier preliminary REJECT is historical and superseded by the final approval. This matrix maps Task 005 conditions to the final architecture; detailed final assessment text was not supplied.

| Condition area | Final architecture resolution | Remaining evidence / gate |
| --- | --- | --- |
| Tenant isolation / scale | Forced RLS, account key, pool/context proof, PostgreSQL 15+ views, dedicated DB placement option | Penetration/isolation suite and tenant-move rehearsal |
| Event-driven ledgering | Immutable GL/stock ledgers + transactional outbox; no blanket event sourcing | DB immutability, balance, and replay tests |
| Inventory atomicity | PostgreSQL row/conditional locks; atomic source/destination moves, saga cross-DB; no Redlock | Concurrency tests, deadlock policy, reconciliation drill |
| Offline/edge resilience | Standalone local profile; signed offline licence and deterministic conflict ownership; hybrid active-active deferred | On-prem install, offline lease and recovery exercise |
| Hierarchical master data | Account/entity/property/outlet config inheritance with version/provenance; guest MDM deferred | Override precedence and SoD requirements approved |
| Licensing | Entitlement service plus signed cached validity manifest; no OPA dependency initially | Meter/grace/suspension policy and key ceremony approved |
| PCI/privacy/SoD | Provider-hosted/terminal card entry, no PAN; contextual authorization and separation of duties; encrypted high-risk PII | Provider/compliance review and counsel approval |
| Integrations | Versioned API, outbox/inbox, vault-backed connections, idempotency/reconciliation | Provider-specific failure contracts; no Kafka/RabbitMQ absent scale evidence |
| Domain completeness | PMS/POS/procurement/accounting/channel blueprints retained as phased scope | Product selects first workflow; modules remain unimplemented |
| Operational readiness | Automated reconciliations, tenant erasure, backups, launch-profile gates | RPO/RTO/NFR, privacy retention and service support policy approved |


## 24. Updated architecture decision register

**Decision states:** Proposed — awaiting Chairman decision; Ratified — formally approved by the Chairman; Superseded — replaced by a later decision; Deferred — deliberately postponed. ADRs 002, 003, and 011 are Ratified by the Chairman on 2026-11-06; remaining states are shown below. Status describes governance disposition, not permission to implement. Architecture approval and implementation authorization are separate. See the package ADR register for rationale and individual ADR records.

| ID | Decision | State | Rationale / implementation boundary |
| --- | --- | --- | --- |

| ADR-002 | PostgreSQL 15+ tenant database with forced RLS, account scope, separate platform/client/migration roles | Ratified | Tenant tables blocked until G2 evidence and acceptance. |
| ADR-003 | Transaction-local tenant context; sanitized pooled connections | Ratified | Tenant tables blocked until G2 evidence and acceptance. |
| ADR-004 | Cloud SaaS and standalone single-account on-premise profiles; no hybrid active-active at launch | Proposed | Launch direction awaits Chairman decision; each profile blocked until G5 qualification. |
| ADR-005 | Laravel queue plus transactional outbox/inbox, at-least-once delivery and idempotent consumers | Proposed | Integration work follows scope approval and relevant G4/G5 evidence. |
| ADR-006 | PostgreSQL transactions, row locks and constraints for reservations/inventory | Proposed | Inventory/reservation work blocked until G4 concurrency and consistency evidence. |
| ADR-007 | Laravel entitlement service, signed manifest and offline verifier | Proposed | Licensing work blocked until C-03 and G5 acceptance. |
| ADR-008 | Deterministic, domain-specific offline conflict resolution | Proposed | Cross-site/cloud multi-writer remains excluded at launch. |
| ADR-009 | Append-only GL and inventory ledgers, DB-enforced immutability and reconciliation | Proposed | Finance/inventory work blocked until G3/G4 evidence and policy decisions. |
| ADR-010 | Account → legal entity → property → outlet model; account is RLS key | Proposed | Architecture proposal; tenant business use remains subject to G2 and scope decisions. |
| ADR-011 | UUIDv7 identifiers | Ratified | Foundation use follows tooling and index compatibility validation under C-09. |
| ADR-012 | Filament platform panel; client UI selected after prototype | Proposed | Interface choice does not authorize application scaffolding. |
| ADR-013 | Signed outbound telemetry; no inbound control-plane tunnel | Proposed | Telemetry work subject to C-02 and G5. |
| ADR-014 | Tenant DEK-based erasure plus hold-aware lifecycle | Proposed | Guest data blocked until C-07 and G6 acceptance. |
| ADR-015 | PostgreSQL security-invoker views for tenant data | Proposed | Tenant views blocked until G2 runtime-role evidence. |
| ADR-016 | Redis Redlock, generic CRDT, OPA, Kafka/RabbitMQ, microservices | Deferred | Not launch prerequisites; revisit only on measured need and separate ADR. |
| ADR-017 | Consolidated platform/client boundaries; Laravel modular monolith | Proposed | Shared core without premature services; no scaffolding authorized. |

## 25. Updated risk register

| Risk | Rating | Mitigation / control | Owner / gate |
| --- | --- | --- | --- |
| Tenant context survives pool reuse or leaks across Octane requests/jobs | Critical | Transaction-local scope, pool sanitization/eviction, scoped Laravel context, adversarial surface tests | Platform engineering; Gate G2 |
| SQL injection or compromised app credential can set tenant GUC | High residual | Safe queries, least privilege, network/secrets controls, RLS as defense against omissions, dedicated DB credentials for higher assurance | Security; threat-model review |
| Posted financial ledger can be altered by privileged app/DB role | Critical | Separate owner, revoke mutation/truncate, triggers/procedure validation, tamper-evident audit, reversals | Accounting + DB owner; Gate G3 |
| Ledger and payment/fiscal sources drift | High | Automated recurring reconciliation, exception ownership, period-close block thresholds | Finance; Gate G4 |
| Concurrent stock/reservation operations oversell or drift balances | Critical | DB locks/constraints and same-transaction movement/projection/outbox, deterministic ordering, rebuild/reconcile | Domain engineering; Gate G4 |
| Offline licence copy, replay or clock rollback extends entitlement | High | Signed install-bound manifest, sequence/last-trusted time, bounded grace, alerts and safe restriction | Platform security; Gate G5 |
| WAN outage causes cloud/edge split brain | High | Standalone-local authority; no hybrid active-active launch; bounded channel allocations | Product/operations; Gate G5 |
| Control-plane telemetry is forged/replayed or leaks PII | High | mTLS/signature, sequence/nonce, schema allowlist, minimization, outbound only, dedupe | Platform security; Gate G5 |
| Erasure conflicts with accounting/tax/legal holds or backup copies | High | Counsel-approved retention classes, tenant DEK destroy, explicit legal hold, restore/erasure drills | Privacy/legal + operations; Gate G6 |
| Support access bypasses client policy or persists after expiry | High | Grant-bound principal, step-up MFA, per-request validation, immediate revocation, dual identity/read audit | Security/support; Gate G2/G6 |
| Noisy tenant impairs other accounts | Medium/High | Fair queues, rate limits, account-led indexes, measured partitions/read models, DB placement | SRE; NFR gate |
| On-prem upgrade/restore fails or strands site | High | Signed bundle, offline preflight/backup, tested upgrade/restore, rollback/forward-fix and support runbook | Release engineering; Gate G7 |
| Privacy/PCI/jurisdiction requirements not met | Critical before data launch | No PAN, encrypted high-risk PII, controller/processor terms, counsel sign-off per market | Legal/security; Gate G6 |
| Detailed final review reports contain additional undisclosed conditions | Medium | Current mapping uses final status + Task 005 conditions and original supplied review findings; add exact source references if reports arrive | KAB; Gate G1 |

## 26. Updated implementation gates

Gate states and acceptance records are maintained in the package [implementation-gates.md](final-review-package/implementation-gates.md). **G1–G7 are Open / not passed; no evidence or verifier acceptance is recorded.** The checklist defines evidence, responsible verification, acceptance criteria, and blocked activity for each gate. Passing a gate is necessary but does not itself authorize implementation; explicit Chairman authorization and scoped work approval remain separate. Hybrid cloud/edge active-active remains excluded at launch and requires a separate ADR, explicit approval, and qualification.

| Gate | Required evidence / verifier | Acceptance criterion | Blocks | State |
| --- | --- | --- | --- | --- |
| G1 | KAB provenance and Chairman decision records; KAB secretary/architecture owner verify | Outcomes recorded accurately, decision states consistent, scope and authorities explicit | Application scaffolding and tenant/business scope pending explicit authorization | Open — not passed |
| G2 | Runtime-role, RLS, pool, view and Laravel surface proof; security/platform/database reviewers verify | Fail closed on missing/stale context; no cross-tenant access or context bleed; coverage checks pass | Tenant business tables and tenant-scoped modules | Open — not passed |
| G3 | Privilege review, mutation denial, posting/reversal evidence; database/finance/security verify | Runtime cannot mutate posted ledgers; valid balanced posting and compensating correction only | Financial posting and inventory-ledger work | Open — not passed |
| G4 | Concurrency, atomicity, replay, reconciliation and rebuild evidence; domain/finance/operations verify | No oversell/drift; idempotent atomic movements; variances detected and close blocked per policy | Inventory/reservation transactions and financial close work | Open — not passed |
| G5 | Profile install/recovery, licence and telemetry evidence; release/security/product verify | Each claimed profile qualifies independently; offline controls safe; telemetry authenticated/minimized; no hybrid active-active | Profile support/marketing and offline licence/telemetry work | Open — not passed |
| G6 | Support, counsel, retention/erasure and payment evidence; security/privacy/legal/operations verify | Scoped audited support; approved jurisdiction rules; erasure covers plaintext and copies; no raw PAN | Guest data, privileged support and production offboarding | Open — not passed |
| G7 | SLO/load, restore/update drills, runbooks and vertical-slice decision; SRE/release/KAB verify | Targets met; drills succeed; runbooks ready; scoped slice accepted with prerequisite gates | Production pilot/release and work outside separately authorized slice | Open — not passed |
## 27. Final outstanding Chairman decision register

Every item below is **Proposed — awaiting Chairman decision**. Recommendations are defaults for consideration, not approved policy. No decision is Ratified by this package.

| ID | Chairman decision required | Recommended default | Must resolve by | State |
| --- | --- | --- | --- | --- |
| C-01 | Ratify RLS and connection-pool controls and close the architecture review | Accept design direction; require G2 evidence before tenant features | G1 | Ratified |
| C-02 | Decide cloud/on-premise commercial launch order and profile | Cloud first; standalone on-prem only after G5; no hybrid active-active launch | G1/G5 | Proposed |
| C-03 | Decide offline licence duration, grace, clock rollback response and restricted actions | Bounded signed lease; preserve safe completion, settlement, export and restore | Before licence build | Proposed |
| C-04 | Decide emergency support access if client admin is unavailable | Disabled unless dual approval, step-up MFA, short scope, notice and review | Before support build | Proposed |
| C-05 | Decide reconciliation cadence, close tolerances and exception authority | Daily and at close; no automatic write-off; block close for material variance | Before accounting scope | Proposed |
| C-06 | Decide negative stock, costing, recipe depletion and inter-entity transfer policy | No unapproved negative stock; approve valuation/depletion/transfer rules | Before inventory/procurement | Proposed |
| C-07 | Decide retention, residency, lawful erasure/holds and DEK retention | Market-specific counsel rules; no guest data before sign-off | Before guest data | Proposed |
| C-08 | Decide RPO/RTO, uptime, performance, capacity and support SLA | Tiered targets measured against pilot workloads | Before production pilot | Proposed |
| C-09 | Ratify PostgreSQL 15+ baseline, upgrade matrix, and UUIDv7 subject to compatibility validation | PG15+; define upgrade matrix; validate UUIDv7 library/index compatibility | Foundation work | Ratified |
| C-10 | Decide subscription meters and billing/regulatory integration scope | Separate licence/subscription states; add country adapters after scope | Before billing release | Proposed |
| C-11 | Ratify identity/MFA and POS device policy | MFA for platform/client admins; registered terminals and shift-bound sessions | Before identity/device release | Ratified |
| C-12 | Decide trigger for dedicated tenant databases | Contract/residency need or measured capacity/security threshold | Before enterprise contract | Proposed |
| C-13 | Decide whether to introduce OPA/Kafka/RabbitMQ/CRDT or other advanced technology | Defer absent measured need and separate ADR | Scale review | Proposed |

**Implementation authorization:** This architecture and decision register grant no general implementation authority. A separate Chairman resolution recorded 2026-11-06 authorizes only the bounded Task 007 foundation scope; tenant business tables and tenant-scoped work remain blocked until G2 acceptance.





