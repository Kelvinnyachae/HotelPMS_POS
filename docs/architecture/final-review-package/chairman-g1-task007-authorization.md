# Chairman Resolution — G1 Closure and Task 007 Authorization

**Date:** 2026-11-06  
**Authority:** Chairman direction in the KAB/011 review thread.  
**Disposition:** G1 Passed for governance closure. Task 007 platform-foundation work authorized within the limits below.

## Decisions recorded

The Chairman ratified the package defaults for C-01, C-09, and C-11:

- **C-01:** Accept PostgreSQL forced RLS, account tenant key, separate roles, transaction-local context, and sanitized pooled-connection controls as the architecture direction. G2 runtime evidence is mandatory before tenant business tables or tenant-scoped features.
- **C-09:** Adopt PostgreSQL 15+ as the baseline; establish an upgrade support matrix; use UUIDv7 subject to tooling and index compatibility validation.
- **C-11:** Require MFA for platform and client administrators; use registered POS terminals and shift-bound sessions. Provider selection and implementation evidence remain assigned to the identity design and G2/G6 as applicable.

## G1 acceptance record

- KAB disposition: accept governance closure and proceed under the Chairman's explicit, limited Task 007 authorization.
- Reviewer provenance: Claude Code and Gemini outcomes remain reported as **APPROVE WITH CONDITIONS**. Their original final letters remain unavailable; no missing wording is inferred. This limitation is accepted and must be revisited if the letters become available.
- Decisions relevant to the authorized foundation have explicit owners and due gates. C-02 (CTO/Product; G5), C-03 (CTO/Security; before licensing build, G5), C-04 (Security/Support; before support build, G6), C-05 (Finance/Accounting; before accounting work, G4), C-07 (Privacy/Legal; before guest data, G6), and C-08 (SRE/Product; before production pilot, G7) remain Proposed and block their listed downstream scopes. C-06 blocks inventory work until its G4 policy decision. C-10, C-12, and C-13 remain deferred to their recorded billing, enterprise, and scale triggers.
- Initial workflow: platform/client identity boundary and tenant-context foundation proof. No PMS business workflow or tenant business tables are included before G2.
- Architecture decisions and implementation authorization are recorded separately.

## Task 007 scope and boundaries

Authorized: establish the Laravel platform foundation, PostgreSQL connectivity/configuration, administrative/control-panel shell, and identity/tenant-context foundation needed to produce G2 evidence. CTO is the accountable delivery lead until this stage's agreed acceptance review; security/platform engineering owns adversarial tenant-isolation verification, and the Chairman retains gate acceptance authority.

Not authorized by this resolution: tenant business tables or business modules before G2 acceptance; financial/inventory posting before G3/G4; guest data or client-data support access before G6; production pilot, release, or commercial deployment before applicable gates; hybrid active-active synchronization; or expansion beyond this scope without further authorization.

**Gate state:** G1 Passed. G2–G7 remain Open / not passed. This resolution does not claim implementation evidence or gate passage beyond G1.