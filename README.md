# Dawineks Hospitality OS

Dawineks Hospitality OS is a proposed commercial platform for independent hotels, hotel groups, resorts, lodges, and restaurants.

## Final architecture for KAB review

- [Consolidated Master Architecture](docs/architecture/master-architecture.md) — authoritative design, final Claude/Gemini finding matrices, updated technology decisions, risk register, implementation gates and outstanding Chairman decisions. Both final reviews are reported as **APPROVE WITH CONDITIONS**.
- [Independent Review Reconciliation](docs/architecture/review-reconciliation.md) — final disposition, source provenance and resolved technology disagreements.

The master supersedes the initial proposals below where they differ. Initial documents are retained as historical foundation work:

- [Platform and client workspace boundaries (initial ADR)](docs/architecture/001-platform-boundaries.md)
- [Platform data model proposal (initial)](docs/architecture/platform-data-model.md)
- [Security and access foundation (initial)](docs/architecture/security-and-access.md)
- [PMS Alpha 0.1 foundation roadmap (initial)](docs/architecture/alpha-0.1-roadmap.md)

## Project status

Laravel 13 foundation scaffold is in place with PostgreSQL configuration and local-only Control Panel and Client Workspace previews. No tenant/business tables or PMS modules have been created. Core local G2 verification passed (7 PostgreSQL tests plus 1 rollback-failure unit test; 37 assertions total), and the RLS coverage guard ran against the empty `tenant` schema. G2 remains open pending hosted CI, wider application-path and real pool-failure evidence, production grant review, and independent security review. The installed local PHP runtime still lacks `pdo_pgsql` and `mbstring`; the passing tests used matching PHP modules temporarily for that verification run. See [G2 evidence](docs/architecture/final-review-package/g2-tenant-runtime-evidence.md).

## Final review package

The self-contained [KAB Final Architecture Review Package](docs/architecture/final-review-package/README.md) includes the complete master architecture, ADRs and decision register, Chairman decisions, implementation gates, critical/high finding dispositions, reconciliation, and Git/change summary.
