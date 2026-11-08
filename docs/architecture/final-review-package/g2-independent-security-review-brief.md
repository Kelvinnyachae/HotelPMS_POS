# Independent Security Review Request — G2 Tenant Runtime

## Requested outcome

Independently review the recorded G2 evidence and implementation at commit `4431f3e` on branch `codex/g2-verification-2026-11-07`. The reviewer must not have authored or implemented the changes under review. Provide an attributable report with findings, severity, affected controls/files, evidence examined, reproduction steps, and a clear accept / accept-with-conditions / reject recommendation for G2. The Chairman retains gate acceptance authority.

## Review boundary

Review the tenant/runtime security foundation and identity-membership authorization path. Do not create or review tenant business modules or business tables as part of this request. G2 remains open and those activities remain blocked until all G2 criteria are evidenced, independently reviewed, and formally accepted.

## Materials

- Repository: `https://github.com/Kelvinnyachae/HotelPMS_POS`
- Branch: `codex/g2-verification-2026-11-07`
- Review commit: `4431f3e` (identity migration, owner-separated tables, identity-membership RLS integration, and CI coverage)
- Governance evidence: `g2-tenant-runtime-evidence.md`
- Gate criteria: `implementation-gates.md`, G2 section
- Core proof and setup: `database/security/g2-proof-fixtures.sql`, `database/security/g2-identity-ci-install.sql`, `.github/workflows/g2-tenant-security.yml`
- Application path: `app/Tenancy/TenantContext.php`, `app/Tenancy/TenantMembershipResolver.php`, `app/Http/Middleware/ApplyTenantContext.php`, `app/Models/User.php`
- Tests: `tests/Feature/PostgresTenantIsolationTest.php`, `tests/Feature/TenantContextConnectionFailureTest.php`
- Latest hosted CI: [Run #45, PostgreSQL 15–18](https://github.com/Kelvinnyachae/HotelPMS_POS/actions/runs/42507556691)

## Review questions

1. Can an authenticated principal forge or reuse signed principal/account/property context to read or write another account or property?
2. Do forced RLS, role ownership, grants, views, and transaction-local cleanup fail closed under malicious input, missing context, retries, connection loss, and connection reuse?
3. Is the identity membership schema and migration strategy safe for production? CI currently runs migrations with administrator credentials; a separate production migration role and production grants/key-custody review are not yet evidenced.
4. Does the User model represent an operational production identity flow? Authentication provider/login, password-reset and credential-protection paths, MFA, and POS device enrollment are not implemented or evidenced.
5. What adversarial checks remain necessary across Filament, Livewire, APIs, file/export paths, jobs/schedules, Octane, external pools, and worker reuse before G2 can pass?
6. Are there critical/high findings or acceptance conditions that must be resolved before tenant business tables or modules are authorized?

## Known evidence limits

- Run #45 verifies the configured PostgreSQL proof fixtures, CI identity-table path, client sign-in, client-administrator MFA flows, and password-reset tests; it does not establish production deployment, production identity provisioning, or production key custody.
- The CI installer uses disposable identity users and CI-only signing/encryption keys.
- POS device enrollment and shift-bound sessions, framework-wide tenant-scope coverage, external pool/long-lived worker behavior, production identity provisioning and grant review remain open. Password-reset functionality has CI coverage in run #45, but production delivery and deployment remain unqualified.

## Handling

Use a private review channel approved by the repository owner. Do not include database credentials, signing keys, production identity data, or environment files. Return the report to the Chairman/CTO governance record. Do not mark G2 passed or authorize tenant business tables based solely on this review.
