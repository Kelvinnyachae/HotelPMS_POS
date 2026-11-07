# ADR 001: Platform and Client Workspace Boundaries

- Status: Superseded — replaced by proposed ADR-017 in the consolidated master architecture. The replacement remains Proposed pending Chairman decision; this historical ADR does not record ratification or implementation authorization.
- Date: 2026-11-06

## Context

Dawineks Hospitality OS has a central Dawineks Control Panel and client company workspaces. Platform operators need to administer company accounts, properties, plans, subscriptions, licences, platform settings, usage summaries, and governed support access. Client users need access only to their own company and authorized properties. Platform administration must not imply default access to client operational data.

## Decision

Start as one modular Laravel application and one deployment unit, with distinct platform and client interfaces, route groups, identity domains, policies, and application services. Keep domain boundaries explicit so interfaces can be deployed separately later without introducing microservices now.

Filament is the preferred candidate for the internal control panel. Select the client interface after validating its workflows; use Vue only where it provides clear value. Do not maintain two frontend stacks by default.

Platform identities and client identities are separate principals with separate guards or equivalent authentication boundaries, role namespaces, and authorization policies. Platform services expose administrative account metadata and aggregated usage. Client operational records are unavailable to platform operators absent an explicit, scoped support grant.

## Initial data boundary

Use a shared PostgreSQL database initially, with a non-null `company_id` on every company-owned row and a `property_id` on property-scoped rows. Enforce property/company consistency with database constraints where practical, and enforce authorization in policies and application services. PostgreSQL row-level security may be assessed as defense in depth, not as the only access control.

## Consequences

- One deployment keeps initial operations and development straightforward.
- Explicit identity and authorization boundaries reduce accidental privilege crossover.
- Tenant scope must be propagated to queries, APIs, exports, scheduled jobs, and queued work.
- Separate deployments or databases remain possible if contractual isolation or scale later requires them.
- KAB must review the support-access and emergency-access policy before operational client data is introduced.

## Revisit when

Customer isolation obligations, availability requirements, independent release cadence, or operational scale demonstrate a need for separately deployed applications or databases.

