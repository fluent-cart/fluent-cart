# AGENTS.md

## Repository Purpose
FluentCart is a WordPress eCommerce plugin with a custom `fc_*` database schema and WPFluent framework architecture. It is not WooCommerce-compatible and relies on plugin-managed tables, services, and background jobs for core store behavior.

## Stack and Runtime Requirements
- WordPress plugin runtime (inside a local WordPress install).
- Database engine: MySQL/MariaDB (SQLite is explicitly unsupported in bootstrap checks).
- PHP compatibility target: 7.4-8.4 (`dev/phpcs.xml`); root `composer.json` platform is PHP 7.4.
- Node.js `>=20.0.0` (`package.json` engines).
- NPM toolchain for Vite builds and frontend bundles.
- Composer for PHP dependencies (`vendor/`) and optional dev QA toolchain (`dev/vendor/`).
- Vite local config defaults are in `config/vite.json` (host `localhost`, port `8880`).
- CI is minimal/stale for day-to-day validation (`.github/workflows/node.js.yml` targets `build` branch and release packaging); local validation plus manual QA are the primary quality gate.

## Rapid Navigation Map
- Plugin bootstrap/lifecycle:
  `fluent-cart.php`, `boot/app.php`, `app/Hooks/Handlers/ActivationHandler.php`, `app/Hooks/Handlers/DeactivationHandler.php`
- API routes/controllers/policies:
  `app/Http/Routes/`, `app/Http/Controllers/`, `app/Http/Policies/`, `api/Resource/`
- Business logic:
  `app/Services/`, `app/Modules/`
- Data model and schema:
  `app/Models/`, `database/Migrations/`, `database/DBMigrator.php`
- Runtime config and REST versioning:
  `config/app.php`, `config/vite.json`
- Admin UI (Vue):
  `resources/admin/`
- Public storefront/checkout UI:
  `resources/public/`
- Build and packaging:
  `vite.config.mjs`, `resources/dev/`, `config/vite.json`, `assets/`
- Dev QA tooling:
  `dev/phpcs.xml`, `dev/phpstan.neon`, `dev/phpunit.xml.dist`, `dev/test/`
- Global helpers and framework boot:
  `boot/globals.php`, `boot/`

## Project Layout (important paths only)
- `fluent-cart.php`: plugin entry point and constants.
- `boot/`: app bootstrapping and framework wiring.
- `app/`: PHP application code (services, modules, models, HTTP layer, hooks, listeners).
- `api/`: API utility/resource layer.
- `database/`: migrators, seeders, overrides, DB migrator orchestration.
- `resources/`: Vue/React/public assets and build scripts.
- `assets/`: built frontend output.
- `config/`: runtime/build config JSON/PHP.
- `dev/`: standalone QA/tooling setup (PHPStan, PHPCS, PHPUnit scaffolding).
- `.github/workflows/`: CI automation (currently minimal/non-blocking for main development flow).

## Architecture Overview
- Boot and lifecycle flow:
  `fluent-cart.php` defines constants and loads `boot/app.php`; `boot/app.php` builds the app container, registers activation/deactivation hooks, loads Action Scheduler bootstrap, and triggers plugin init hooks.
- Activation/deactivation flow:
  `ActivationHandler::handle()` runs `DBMigrator::migrateUp()` and schedules recurring tasks.
  `DeactivationHandler::handle()` clears plugin scheduler hooks.
- Route composition flow:
  `app/Http/Routes/routes.php` aggregates `api.php`, `reports.php`, `frontend_routes.php`, and `advance_filter_routes.php`.
- Request handling flow:
  Route -> Controller -> Service/Module -> Model/Resource -> response.
  Policies are attached at route group/endpoint level (`withPolicy(...)`).
- Data/schema flow:
  `DBMigrator` orchestrates all migration classes, runs schema upgrades, and updates `_fluent_cart_db_version`.
- REST contract flow:
  Namespace/version come from `config/app.php` (`rest_namespace`, `rest_version`) and are consumed by helper methods in `app/Helpers/Helper.php`.

End-to-end execution path (admin API):
1. A request hits `/wp-json/fluent-cart/v2/...`.
2. Route is defined in `app/Http/Routes/api.php` (often under `prefix(...)->withPolicy(...)`).
3. Controller in `app/Http/Controllers/` validates and delegates to service/module code.
4. Domain changes persist through models/custom tables (`fc_*`).
5. Resource/response payload returns to the admin SPA in `resources/admin/`.

## Critical Invariants and Contracts
- Keep custom schema compatibility: treat `database/Migrations/` and `DBMigrator` changes as upgrade-sensitive; prefer additive/idempotent migration behavior.
- Preserve storage contracts: avoid silent renames/removals of `fc_*` tables, columns, meta keys, and option keys.
- Preserve API contracts: avoid breaking route signatures, response shapes, and expected permissions without explicit migration/versioning intent.
- Preserve extension surface: do not remove or repurpose existing hooks/filters/events without compatibility handling.
- Keep service boundaries: controllers coordinate IO; business rules stay in services/modules, not in view/render/controller glue.
- Keep lifecycle safety: activation must remain safe across single-site and multisite paths; deactivation must clean up recurring hooks.
- Keep scheduler behavior stable: when changing async logic, verify schedule creation and unscheduling paths.
- Keep DB engine expectations explicit: MySQL/MariaDB is required; SQLite remains unsupported.
- Keep frontend build contracts: `vite` outputs and manifest-driven asset loading must stay intact for admin/public entry points.
- Keep localization contract: changed user-facing strings remain translatable and use project text domain.

## Preferred Patterns and Anti-Patterns
- Prefer:
  Route policy + permission metadata in route definitions for protected endpoints.
- Avoid:
  Adding privileged endpoints without policy/capability gating.

- Prefer:
  Service/module orchestration for business logic (`app/Services/`, `app/Modules/`).
- Avoid:
  Embedding domain logic directly in controllers, hooks, or render layers.

- Prefer:
  Model/query abstractions and existing data access patterns.
- Avoid:
  Ad-hoc direct SQL in feature code unless required for migrators/upgrade tasks.

- Prefer:
  Additive migration strategy with explicit upgrade handling in `DBMigrator`.
- Avoid:
  Destructive or implicit schema changes without backward-compatibility planning.

- Prefer:
  Explicit sanitization on write and context-appropriate escaping on output.
- Avoid:
  Trusting request payloads or rendering unsanitized user-controlled data.

- Prefer:
  Minimal targeted edits in relevant modules.
- Avoid:
  Cross-cutting refactors that widen risk without requirement.

- Prefer:
  Existing frontend organization (`resources/admin`, `resources/public`, block editor split).
- Avoid:
  Mixing admin/public concerns in shared entry points without clear loading boundaries.

## Standard Local Workflow
1. Confirm runtime versions (`node`, `npm`, `php`, `composer`) meet project constraints.
2. Install dependencies as needed:
   `npm i`, `composer install`, and optionally `(cd dev && composer install)` for PHP QA tools.
3. Run frontend development server for UI work: `npm run dev`.
4. Implement changes in the smallest relevant paths (use the navigation map above).
5. Run targeted validation (build/lint/static analysis/test commands relevant to touched areas).
6. Perform manual QA in a WordPress install (admin + frontend + lifecycle + security checks).
7. Record QA evidence and known gaps before handoff.

## Command Matrix
| Purpose | Command | Preconditions | Expected Result | Status |
|---|---|---|---|---|
| Verify runtime toolchain | `node -v && npm -v && php -v \| head -n 2 && composer --version` | Node, npm, PHP, Composer installed | Prints versions for all tools | Validated (2026-02-17). Observed Node 20.19.1, npm 10.8.2, PHP 8.5.3, Composer 2.5.8; Composer emits deprecation notices on PHP 8.5. |
| Check available npm scripts | `npm run` | `package.json` present | Lists supported local scripts | Validated (2026-02-17). |
| Check root composer scripts | `composer run --list` | Composer available | Lists root scripts (`post-install-cmd`, `post-update-cmd`) | Validated (2026-02-17) with Composer deprecation noise. |
| Check dev composer scripts | `(cd dev && composer run --list)` | `dev/composer.json` present | Lists `phpcs` and `phpstan` scripts | Validated (2026-02-17) with Composer deprecation noise. |
| Quick PHP syntax smoke check | `php -l fluent-cart.php` | PHP installed | `No syntax errors detected` | Validated (2026-02-17). |
| Install JS dependencies | `npm i` | Network access; writable workspace | Populates/updates `node_modules` | Not validated (network/write-heavy, skipped). |
| Start frontend hot-reload | `npm run dev` | Node deps installed; local WP runtime for practical verification | Vite dev server starts and watches files | Not validated (long-running command, skipped). |
| Build production assets | `npm run build` | Node deps installed | Regenerates production assets/manifests | Not validated (writes build artifacts, skipped). |
| Build zip package | `npm run build:zip` | Build prerequisites met | Produces distributable zip via `resources/dev/zip.js` | Not validated (artifact-producing, skipped). |
| Refresh translation outputs | `npm run translate:all` | Node deps installed | Updates frontend/backend translation extraction outputs | Not validated (potentially large write set, skipped). |
| Install root PHP dependencies | `composer install` | Network access; writable workspace | Installs/updates `vendor/` and runs `dev/ComposerScript.php` hooks | Not validated (network/write-heavy, skipped). |
| Install dev PHP QA dependencies | `(cd dev && composer install)` | Network access | Installs `dev/vendor/` QA tooling | Not validated (network/write-heavy, skipped). |
| PHP compatibility scan | `(cd dev && composer run phpcs)` | Dev composer deps installed | Runs PHPCompatibility + WP compatibility scan on `../app` | Not validated (toolchain install not executed in this pass). |
| Static analysis scan | `(cd dev && composer run phpstan)` | Dev composer deps installed | Runs PHPStan level 2 against `../app` | Not validated (toolchain install not executed in this pass). |
| Legacy PHPUnit flow | `(cd dev && ./test/setup.sh <db> <user> <pass> <host>)` then `(cd dev && vendor/bin/phpunit -c phpunit.xml.dist)` | MySQL test DB + WordPress test libs configured | Executes tests under `dev/test/tests` | Not validated (environment-dependent; setup required). |

## Manual QA Flow
1. Environment baseline:
   Use a clean WordPress install with MySQL/MariaDB and FluentCart activated from this working copy.
2. Install and lifecycle:
   Activate plugin and confirm no fatal errors/notices; verify migrations complete.
   Deactivate/reactivate plugin and verify scheduler hooks are unscheduled/rescheduled correctly.
   Confirm expected behavior on plugin removal. There is no root `uninstall.php`; treat uninstall data policy as explicit-product-decision territory.
3. Settings and persistence:
   Save critical store settings (currency, checkout, tax/shipping/payment-related settings), reload admin, and confirm persistence.
   Validate defaults when options/meta are missing or reset.
4. Security and authorization:
   Verify capability checks on privileged admin/API actions.
   Verify nonce checks on state-changing requests.
   Verify sanitize-on-input and escape-on-output for changed fields.
5. Runtime behavior:
   Test impacted admin flows (orders/products/customers/settings/modules touched).
   Test impacted frontend flows (cart, checkout, customer profile, blocks/widgets touched).
   Test changed REST/AJAX endpoints end-to-end.
6. Async and schedulers:
   Validate Action Scheduler/WP-Cron behavior for any touched background jobs.
7. Compatibility/regression:
   Confirm existing hooks/filters still fire as expected.
   Confirm schema/options changes are backward-compatible for upgrades.
   Confirm changed strings remain translatable.
8. Evidence capture:
   Record what was tested, environment assumptions, pass/fail result, and known gaps.

## Coding and Style Rules
- Keep business logic in `app/Services/` or dedicated module services, not in controllers/views.
- Use existing architecture boundaries: routes -> controllers -> services -> models/resources.
- Preserve backward compatibility for custom tables, options/meta keys, API shapes, and hooks.
- Maintain PHP 7.4-compatible syntax/features unless the project baseline is explicitly raised.
- Follow WordPress security hygiene: capability checks, nonce validation, sanitization, escaping.
- Respect existing formatting conventions (4-space indentation in PHP per `.vscode/settings.json`).
- Prefer minimal, targeted changes in relevant modules over broad refactors.
- Do not edit `vendor/` manually.

## Change Safety Rules
- Treat activation/deactivation and migration paths as high risk; test lifecycle whenever touched.
- Keep migrations additive/idempotent; provide safe upgrade paths for existing stores.
- Do not silently remove/rename hooks, filters, option keys, DB columns, or API fields.
- When touching scheduler logic, verify both scheduling and cleanup behavior.
- When touching frontend assets, ensure builds still produce expected manifest/asset outputs.
- If docs or scripts are stale, update instructions alongside code changes rather than relying on tribal knowledge.

## Pre-Handoff Checklist
- Confirm scope matches request and touched files are intentional.
- Run the most relevant commands from the matrix and note any skipped checks with reasons.
- Complete manual QA for impacted admin/frontend/lifecycle/security paths.
- Call out compatibility risks (schema/options/API/hooks) explicitly.
- Provide concise reproduction and verification notes for reviewers.
- Ensure no debug-only artifacts or accidental local-only config changes are left behind.

## Search Policy
Use this AGENTS.md first; search only when instructions are missing, outdated, or conflicting.
