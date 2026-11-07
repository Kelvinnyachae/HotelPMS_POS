# Security and Access Foundation

## Identity domains

Platform administrators and client users are separate identity classes. Give each domain distinct authentication entry points, session/cookie scope, role namespace, policies, and audit actor type. A platform role must never automatically satisfy a client policy, and a client role must never reach control-panel routes.

Require MFA for platform administrators. Apply least privilege and separate high-impact duties such as changing plans, suspending licences, and managing other platform users where the operating model permits.

## Tenant and property authorization

1. Derive the active company from the authenticated user's server-side membership, never from an untrusted request parameter alone.
2. Resolve property access through an active company membership and property membership (or an explicitly authorized company-wide role).
3. Scope database queries before retrieving tenant-owned records; authorize each action through policies/application services.
4. Apply the same checks to APIs, exports, background jobs, scheduled tasks, notifications, and file access.
5. Fail closed if tenant context is missing or ambiguous. Do not use a global/unscoped model lookup for tenant-owned records.
6. Add automated tests for cross-company, cross-property, revoked membership, expired licence, and unauthenticated cases before introducing operational domains.

Use database foreign keys and company/property consistency constraints. Evaluate PostgreSQL row-level security as defense in depth with connection-pool context reset and transaction-scope handling carefully documented; do not treat it as a replacement for policy checks.

## Central control panel

By default, the platform can access client account metadata, entitlement state, billing status, and minimized/aggregated usage only. Do not grant routine platform roles read access to guest profiles, reservations, or detailed financial/operational records.

## Support access

Support access is a separate, explicit grant: reason, target company, requested permissions, start/end, approver, requester, and status are mandatory. Prefer client company-administrator approval. Make read-only the default, show active access to the client, log sensitive actions, automatically expire grants, and permit early revocation. Grants must not confer platform-user management or payment credential access.

Any emergency access procedure requires KAB policy approval. If approved, require strong authentication, narrow scope and duration, a recorded reason, immediate alerting, comprehensive audit, and post-event review.

## Audit and sensitive data

Audit privileged actions and access grant lifecycle events with actor, target, tenant/property scope, timestamp, outcome, and correlation ID. Never include passwords, tokens, payment credentials, guest PII, or financial payloads in logs. Protect audit records from routine update/deletion and restrict audit-log access.

Encrypt transport and sensitive stored values, manage keys outside the repository, and establish rotation and backup controls. Use a payment provider for card processing and store only necessary provider references. Define retention and deletion rules before production guest data is collected.
