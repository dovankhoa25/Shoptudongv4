# Unified backend status

This project is an independent copy of `BE_NROCHECK`. It does not load code from
the three source backends at runtime and it does not share their Git working
trees.

## Authentication boundary

- Laravel Passport from `BE_NROCHECK` is the only API user authentication
  mechanism.
- Password login: `POST /api/auth/login`.
- Refresh: `POST /api/auth/refresh`.
- Protected requests use `Authorization: Bearer <access_token>` and the
  `auth:api` guard.
- Each browser frontend is represented by a first-party OAuth client. Direct
  login is accepted only when the supplied `client_id` is active and the HTTP
  `Origin` is in that client's `allowed_origins` list.
- `CORS_ALLOWED_ORIGINS` remains the outer HTTP CORS allowlist and can contain
  multiple comma-separated frontend origins.
- The desktop/bot endpoints under `/app/*` are intentionally separate and use
  their own `APP_API_KEY` authentication.

## Module routes

| Module | Public/client API | Admin UI routes |
| --- | --- | --- |
| Core NROCHECK | `/api/auth/*`, `/api/profile/*` | `/admin/users`, `/admin/transactions`, `/admin/deposits` |
| Webgame | `/api/webgame/*` | `/admin/games/*`, `/admin/services/*`, `/admin/randombox`, `/admin/spins`, and related routes |
| Gold and gems | `/api/trading/*` | `/admin/bots`, `/admin/orders`, `/admin/gem-*`, `/admin/servers`, and related routes |
| Desktop/bot app | `/app/*` | n/a |

The webgame and trading route files are deliberately separate because both old
frontends used overlapping paths such as `/profile/orders` for different data.

## Production database safety

The legacy `backend` and `Vangtudong` migration histories were **not** copied.
The production dump already contains most legacy tables, while its migration
ledger does not describe all of them. Running old `create_*` migrations against
production would therefore fail or, worse, create an inconsistent schema.

No production database connection has been configured and no production
migration has been run from this project.

Before deployment, the next database phase must:

1. Restore a recent production dump into a disposable staging database.
2. Create idempotent adoption migrations that inspect existing tables and add
   only missing Passport/core columns and indexes.
3. Reconcile known schema differences, including Passport `oauth_*` tables,
   `users` lock/status columns, transaction balance column names,
   `gold_transactions.gold_type`, and singular/plural server login tables.
4. Backfill and validate data on staging, then run application smoke tests.
5. Prepare a reversible production rollout and backup/restore procedure.

## Intentionally pending

- Brownfield database migrations and production data backfills.
- API Google/social login that returns Passport access and refresh tokens. The
  legacy social-login controllers were intentionally not imported.
- Updating `webgamev3fe` and `vangtudongfe` to the Passport contract used by
  `fe_nro2`.
- End-to-end tests against a restored production-like database.

## Current verification

- Laravel can register all core, webgame, trading, app, and admin routes.
- Every registered controller action resolves to an existing method.
- PHP syntax checks pass for the imported application code.
- The TypeScript/Vite production build completes.
- The Tool/Mod license module, its sidebar entry, routes, models, services,
  configuration, seeders, tests, and documentation have been removed.
- The admin smoke test renders all 45 static admin pages and validates their
  expected Inertia components.
- The full PHPUnit suite completes with 72 passing tests and 1,418 assertions.
