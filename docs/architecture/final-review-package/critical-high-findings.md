# Critical and High Finding Disposition

This register covers all Critical/High findings in the earlier supplied reviews. Detailed original finding and response matrices remain in [master §15](master-architecture.md#15-claude-findings-resolution-matrix), [§16](master-architecture.md#16-gemini-findings-resolution-matrix), and final condition matrices [§22](master-architecture.md#22-claude-final-finding-resolution-matrix) and [§23](master-architecture.md#23-gemini-final-finding-resolution-matrix).

“Resolved” here means the architecture question is specified. The implementation is still pending and is assigned to gates below.

| Source / priority | Finding | Disposition | Gate / residual |
| --- | --- | --- | --- |
| Claude C1 | Platform/client data separation depended on application scoping | Architecture resolved: separate roles/connections, no platform grants to operational tables, forced RLS, pool/context controls, push-only aggregates | G2 evidence pending; residual SQL injection risk is explicitly accepted and mitigated, higher assurance uses dedicated DB credentials |
| Claude C2 | On-prem/offline deployment unclear | Architecture resolved: standalone local install, signed offline entitlement, outbound authenticated telemetry, no hybrid active-active at launch | G5 qualification and Chairman licence lease decision pending |
| Claude H1 | Support session principal/property scope/read auditing/fallback missing | Defined grant-bound support identity, scoped permissions, MFA, expiry/revocation, read/export audit, two-person emergency proposal | G6 implementation and C-04 Chairman decision pending |
| Claude H2 | Audit integrity weak | DB mutation restrictions, hash chain/WORM sink approach, partition/retention and tenant-visible audit specified | G3/G6 implementation evidence and audit sink/retention decision pending |
| Claude H3 | Account/legal/property/outlet hierarchy too flat | Account tenant root; multiple legal entities; property and outlet scope; group config inheritance | Architecture resolved; detailed fiscal and ownership rules under C-06/C-09 |
| Claude H4 | Entitlement enforcement/state and safe suspension undefined | Central entitlement service; active/grace/restricted/suspended; safe checkout/settlement/export/restore; signed offline cache | G5; duration and exact restrictions C-03 |
| Claude H5 | Client session/device context undefined | Server-bound account/property, scoped token, admin MFA, POS device principal and shift-bound staff context | G2/G6 identity/device tests and C-11 |
| Claude H6 | Privacy/data-protection compliance absent | High-risk encryption, minimization, hold-aware erasure, controller/processor and jurisdiction review | G6; accepted residual until counsel sign-off; no guest data before clearance |
| Gemini Critical | Shared tenancy/RLS/sharding and cross-property operations | Forced account RLS; placement registry/dedicated DB option; explicit same-account transfer and cross-entity saga | G2/G4; tenancy-move proof |
| Gemini Critical | Ledger/inventory consistency and mutation risks | DB-enforced immutable ledgers, transactional locking/movement/projection/outbox, scheduled reconciliation | G3/G4; concurrency/reconciliation evidence |
| Gemini Critical | Missing hierarchical master data and group configuration | Account/entity/property/outlet and versioned inherited config; guest profile consolidation remains deferred | Design specified; D-14 legal/consent decision before global CRM |
| Gemini Critical | Remote-site offline resilience/split brain | Standalone on-prem local authority, signed lease, deterministic conflicts; no CRDT or hybrid active-active at launch | G5; profile and offline qualification |
| Gemini High | PCI scope, PII protection and SoD | No PAN in app; provider-hosted/device payment; high-risk encryption, contextual access and separation of duties | G6/provider review and per-module authorization tests |
| Gemini High | Synchronous integration failure/coupling | Outbox/inbox, idempotency, bounded retry, circuit breaker, reconciliation and vault-backed secrets | G4/G5; provider-specific contracts deferred |
| Gemini High | PMS/POS/inventory/procurement/accounting/channel gaps | Full blueprints exist; feature gaps are explicitly phased, not claimed implemented | Product workflow selection is G1; module release gates apply |

No critical/high finding is left unassigned. Risks that architecture cannot eliminate (compromised app credentials, legal interpretation, offline conflict policy, external provider failure) are explicit in the risk and Chairman registers.
