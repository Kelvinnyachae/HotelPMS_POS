# Outstanding Chairman Decisions

C-01, C-09, and C-11 are **Ratified** by the Chairman on 2026-11-06 using the package defaults. All other items below remain **Proposed — awaiting Chairman decision**. Task 007 authorization is recorded separately and is limited to the platform foundation scope.

Full rationale is in [master architecture §27](master-architecture.md#27-final-outstanding-chairman-decision-register).

| ID | Required decision | Recommended default | Due | State |
| --- | --- | --- | --- | --- |
| C-01 | Ratify RLS and pool controls and close the architecture review | Accept design direction; require G2 evidence before tenant features | G1 | Ratified |
| C-02 | Cloud/standalone on-premise launch order and commercial availability | Cloud first; on-premise only after independent G5 qualification; no hybrid active-active | G1/G5 | Proposed |
| C-03 | Offline licence duration, grace, clock rollback and restricted operations | Bounded signed lease; preserve safe completion, settlement, export and restore | Before licence build | Proposed |
| C-04 | Emergency support access if no client admin is available | Disabled unless dual approval, step-up MFA, short scope, notice and review | Before support build | Proposed |
| C-05 | Reconciliation cadence, close tolerance and exception authority | Daily and at close; no automatic write-off; material variance blocks close | Before accounting | Proposed |
| C-06 | Negative inventory, costing, recipe depletion and inter-entity transfers | No unapproved negative stock; approve costing and accounting policy | Before inventory | Proposed |
| C-07 | Jurisdiction, data residency, guest retention, legal hold and DEK destruction | Market-specific counsel approval before guest data | Before guest data | Proposed |
| C-08 | RPO/RTO, availability, performance and support SLA | Tiered targets with pilot evidence | Before production pilot | Proposed |
| C-09 | PostgreSQL upgrade matrix and UUIDv7 finalization | PostgreSQL 15+; validate UUIDv7 tooling/indexes | Foundation work | Ratified |
| C-10 | Subscription metrics and billing/fiscal provider scope | Separate billing/licence; country adapters after scope | Before billing release | Proposed |
| C-11 | Identity/MFA provider and POS terminal/device policy | MFA for platform/client admins; registered terminals and shift-bound sessions | Before identity release | Ratified |
| C-12 | Threshold for dedicated tenant database | Contract/residency requirement or measured capacity/security trigger | Enterprise contract | Proposed |
| C-13 | OPA, Kafka/RabbitMQ, CRDT or other advanced technology adoption | Defer absent measured need and separate ADR | Scale review | Proposed |

