# G2 tenant runtime evidence

**Status:** Open — partial implementation evidence only. This record does not close G2 or authorize tenant business tables/modules.

**Verification authorization:** The Chairman authorized the CTO to coordinate and complete the remaining G2 verification on 2026-11-07. See [Chairman G2 verification authorization](chairman-g2-verification-authorization.md). This does not constitute G2 acceptance.

## Completed

- The local `dawineks_runtime` login connects to `dawineks_hospitality_os` as a non-owner and without superuser, role-creation, database-creation, or RLS-bypass attributes. The separate owner role is `dawineks_owner` (NOLOGIN).
- Runtime has database CONNECT and public-schema USAGE, but no public-schema CREATE privilege.
- Added transaction-scoped tenant context that requires valid account/property UUIDs and a 256-bit signing key. The middleware consumes only server-resolved request attributes and rejects unauthenticated or unresolved context; client headers are not trusted.
- Added isolated PostgreSQL proof fixtures with forced RLS, account/property scoping, a security-invoker view, a signing-key table unavailable to the runtime login, and a deny-by-default invalid-context function.
- Added automated PostgreSQL tests for role separation, forced RLS, default denial, account/property boundaries, security-invoker views, transaction cleanup, and forged settings; added a PostgreSQL 15–18 CI workflow and a tenant-table RLS coverage guard.
- Local checks completed: PHP syntax checks and Pint passed. The focused middleware test `test_client_supplied_tenant_headers_do_not_create_server_context` passed (1 test, 1 assertion).
- The test-only proof fixture and a random local signing key are installed in the application database; the key is kept in the local ignored `.env` and is not part of the repository.
- The local PostgreSQL feature suite passed as `dawineks_runtime`: 7 tests, 29 assertions. It covers non-owner/no-bypass role attributes, forced RLS, deny-by-default reads/writes, account/property isolation on the table and security-invoker view, transaction cleanup and rollback, rejected client headers, forged custom settings, inaccessible signing-key material, and reuse of the same PostgreSQL backend after context success and failure.
- The rollback-failure path now calls Laravel's connection-manager purge for the affected connection. Its focused test passed: 1 test, 8 assertions. This verifies the purge is requested when rollback fails; a real PostgreSQL reset failure and connection eviction are not simulated by this unit test.
- The tenant-table coverage guard executed successfully against the live PostgreSQL 18.6 database (318 ms). The `tenant` schema currently has no tables, so this verifies guard execution only, not business-table policy coverage.
- Combined verification passed: 8 tests, 37 assertions. The current installed PHP runtime lacks `pdo_pgsql` and `mbstring`; the suite was run with matching PHP 8.5.12 NTS x64 modules loaded temporarily for the test process. No PHP installation or system configuration was changed.
- Hosted verification passed: GitHub Actions run [G2 tenant security proof #1](https://github.com/Kelvinnyachae/HotelPMS_POS/actions/runs/42413331998) completed successfully for commit [`5c2afd4`](https://github.com/Kelvinnyachae/HotelPMS_POS/commit/5c2afd45faf45cc5dcb6c25479bc10113980ed3b) on branch `codex/g2-verification-2026-11-07`. This records successful execution of the configured workflow; it does not supply the missing application-wide integration, real reset/eviction, grant-review, or independent-review evidence below.
- Follow-up hosted verification passed in all four configured PostgreSQL jobs (15, 16, 17, and 18) in run [G2 tenant security proof #3](https://github.com/Kelvinnyachae/HotelPMS_POS/actions/runs/42414339457) for commit [`68a3eaf`](https://github.com/Kelvinnyachae/HotelPMS_POS/commit/68a3eaf10bee397d11ec8c7f6592a41b83c34fce). The workflow's RLS coverage guard and PostgreSQL isolation test step succeeded in each job, including the new middleware checks for authenticated server-resolved context and cleanup after response or exception.

## Not yet evidenced

- No independent security review is recorded. Hosted CI is now evidenced by run [G2 tenant security proof #1](https://github.com/Kelvinnyachae/HotelPMS_POS/actions/runs/42413331998), but its success does not replace the other G2 acceptance evidence.
- This proof does not exercise the application’s actual identity/membership resolver or the Filament, Livewire, API, export/file, queue/schedule, Octane, pooled-connection checkout/release, and error/retry paths required by G2. The backend-PID check proves reuse of one PostgreSQL session across test operations, not behavior of an external connection pool or long-lived worker.
- A real PostgreSQL rollback/reset failure and resulting pool/connection eviction have not been demonstrated. The rollback-failure unit test verifies the Laravel connection manager is asked to purge the connection.
- No separate migration login or full production grant review has been qualified.

## Disposition

G2 remains **Open / not passed**. Tenant business tables and tenant-scoped modules remain blocked pending the missing integration, production-grant, and independent-review evidence listed in `implementation-gates.md`.
