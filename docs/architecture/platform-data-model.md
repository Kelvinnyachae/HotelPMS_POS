# Platform Data Model Proposal

This proposal covers only platform administration and security foundations. It does not define PMS business records.

## Entities

| Entity | Purpose and key relationships |
| --- | --- |
| `platform_users` | Dawineks staff identities, separate from client principals. |
| `companies` | Tenant root for a client organization. |
| `properties` | Belong to exactly one company. |
| `client_users` | Client identity records, distinct from platform users. |
| `company_memberships` | Links a client user to a company and company-scoped role. |
| `property_memberships` | Links a company membership to an authorized property and property-scoped role. |
| `roles`, `permissions`, `role_permissions` | Permission definitions; platform and client namespaces must not overlap implicitly. |
| `plans` | Plan catalogue, limits, and feature definitions. |
| `subscriptions` | Company plan, term, renewal, cancellation, and billing status. |
| `licences` | Company entitlement activation, suspension, expiry, and status history. |
| `feature_entitlements` | Effective plan features and explicitly approved company overrides. |
| `invoices`, `payment_records` | Billing records and provider references; never payment card data. |
| `support_access_grants` | Requester, approver, company, scope, reason, start/end, status, and revocation. |
| `audit_events` | Actor, action, target, scope, timestamp, result, and correlation ID. Avoid sensitive payloads. |
| `usage_aggregates` | Minimized counters for monitoring and plan limits; avoid guest-level detail. |

## Relationships

```text
Company 1 ── * Property
ClientUser 1 ── * CompanyMembership * ── 1 Company
CompanyMembership 1 ── * PropertyMembership * ── 1 Property
Company 1 ── * Subscription * ── 1 Plan
Company 1 ── * Licence
Company 1 ── * SupportAccessGrant
Actor 1 ── * AuditEvent
Company 1 ── * AuditEvent
```

Use UUID or another non-sequential public identifier for externally exposed resources while retaining suitable indexed primary/foreign keys. Every future tenant-owned table must include `company_id`; property-scoped rows must also include `property_id`. Enforce that the referenced property belongs to the same company using composite keys/constraints where supported. Do not rely on obscured identifiers for authorization.

## Lifecycle and integrity

- Keep subscription billing state separate from licence entitlement state.
- Record licence/subscription state transitions with actor, old/new state, time, and reason.
- Derive effective entitlements from plan plus approved overrides; keep an auditable source for overrides.
- Keep audit events append-only at the application boundary, with database/service protections against routine modification.
- Apply retention, backup, export, and deletion rules only after KAB policy review.
