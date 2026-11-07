# PMS Alpha 0.1 Foundation Roadmap

This roadmap separates approved foundation preparation from the later PMS business modules. No PMS module is authorized by this document.

## Phase 0 — KAB review and product decisions

- Review ADR 001, the proposed platform/client identity split, and the shared-database tenant model.
- Approve role definitions, support approval and emergency-access rules, audit retention, and sensitive-data policy.
- Confirm Alpha billing scope (manual invoicing or provider integration), licence grace/suspension behavior, and supported deployment targets.
- Select the first operational workflow only after the security foundation is accepted.

## Phase 1 — Repository and runtime baseline

- Confirm supported PHP, Laravel, PostgreSQL, Node, and package versions against the target deployment environment.
- Establish application bootstrap, environment example, local setup instructions, secret handling, code style, static checks, and CI.
- Add development provisioning for PostgreSQL and Redis and a documented cloud/on-premise configuration approach.
- Preserve existing repository content and review the untracked `.github/` configuration before staging or committing it.

## Phase 2 — Central platform foundation

- Implement separate platform and client authentication domains and platform administrator MFA.
- Add company/property registration, platform-user management, roles/permissions, plans, subscriptions, licences, and feature entitlements.
- Add audit events, minimized usage aggregates, and central monitoring summaries.
- Implement governed support grants, expiry, revocation, client visibility, and alerts only after policy approval.

## Phase 3 — Tenant security verification

- Implement trusted tenant-context resolution and property membership checks.
- Add authorization coverage for cross-company and cross-property denial, role boundaries, licence states, and support grant lifecycle.
- Review query, API, export, job, file, log, backup, and database constraints for tenant leakage.
- Conduct a threat-model and security review before storing client operational or guest data.

## Phase 4 — First PMS workflow (post-approval)

- Select one narrow workflow with KAB and document acceptance criteria.
- Implement it within company/property scopes, with audit events and automated tests alongside development.
- Validate usability, reporting, backup/restore, deployment, and authorization before adding further PMS or POS scope.

## Release gate

Alpha 0.1 is not ready until KAB has accepted scope, tenant and role tests pass in CI, platform/client identities remain separated, support access meets approved governance, and installation/deployment steps are reproducible.
