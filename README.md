# koh/crowdsec-bridge

A TYPO3 14 backend module that bridges the operationally relevant subset of the
[CrowdSec](https://www.crowdsec.net/) **Local API (LAPI)** into the TYPO3 backend. List, create
and delete decisions, look up IP ban status, inspect alerts, and check LAPI heartbeat — all
without container shell or SSH access.

The extension is **read-only by default** and only exposes write operations to backend users
who hold the dedicated operator role. Every write attempt is recorded in `sys_log` for
forensic traceability. Foreign-origin decisions (created by CrowdSec or `cscli`) are protected
against accidental deletion.

- **Composer name**: `koh/crowdsec-bridge`
- **Extension key**: `crowdsec_bridge`
- **License**: MIT
- **Current release**: 0.4.0

---

## Table of contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Configuration](#configuration)
   — [Bouncer API key](#bouncer-api-key) · [Machine account](#machine-account)
5. [Backend permissions](#backend-permissions)
6. [Usage](#usage)
7. [Architecture](#architecture)
8. [Security model](#security-model)
9. [Audit trail (sys_log)](#audit-trail-sys_log)
10. [Optional cache (keyvalue-store)](#optional-cache-keyvalue-store)
11. [Localisation](#localisation)
12. [Docker compose example](#docker-compose-example)
13. [Troubleshooting](#troubleshooting)
14. [Tests and quality gates](#tests-and-quality-gates)
15. [Versioning and changelog](#versioning-and-changelog)
16. [Contributing](#contributing)
17. [License](#license)

---

## Features

The module exposes a single backend module at **System → CrowdSec** with two tabs.

### Decisions tab

- **Paginated list** of all active decisions (50 per page), sorted by creation time
  descending. Columns: ID, scope, type, value, remaining time (computed from `created_at +
  duration − now`), origin, scenario.
- **Filters** for scope (`Ip` / `Range` / `Country` / `As`), type (`ban` / `captcha`), and
  origin (exact-match free text). Filters persist across pagination.
- **LAPI heartbeat banner** showing connection status, CrowdSec version, bouncer name, and
  the timestamp of the last check.
- **IP quick-check** input field returns a binary `banned` / `not banned` answer in under 2
  seconds, including decision ID and remaining time on a hit. Server-side IP validation; every
  check is audit-logged.
- **Add decision** form for operators with fields: scope, type, value, duration preset
  (1h/24h/7d/30d/custom), reason (5–1000 chars). The server hardcodes `origin = "typo3"`;
  user input cannot override it.
- **Delete decision** through a TYPO3-native confirmation modal. Foreign-origin rows show
  no delete button unless the override is explicitly enabled (admin-only).

### Alerts tab

- **Paginated list** of recent CrowdSec alerts (50 per page). Columns: ID, scenario, source
  scope, source value, creation time, number of linked decisions.
- **Filters** for source scope (delegated to LAPI as exact-match) and scenario
  (case-insensitive substring, applied client-side after fetch — `ssh` matches
  `crowdsecurity/ssh-bf` as well as `crowdsecurity/ssh-slowloris`).
- **Detail view** with all alert metadata: source (scope, value, IP, AS number + name,
  country, latitude/longitude), labels, and up to 50 linked decisions as a secondary table.
  Direct URL access is supported (bookmarkable).
- Fully **read-only** — alerts are CrowdSec-internal events, no write actions are exposed.

### Cross-cutting

- **DDD 4-layer architecture**, enforced by deptrac at CI time.
- **PSR-18 HTTP client** provided by TYPO3 14 (GuzzleHttp under the hood).
- **PHPStan level 8** with strict-rules.
- **Conventional Commits** enforced via commitlint git hook.
- **REUSE/SPDX** compliance enforced via pre-commit hook.

---

## Requirements

| Component | Minimum version |
|---|---|
| PHP | 8.5 |
| TYPO3 CMS | 14.3 |
| CrowdSec | 1.6 |
| `psr/http-client` | 1.0 |
| `psr/http-factory` | 1.0 |

Optional but recommended:

| Component | Purpose |
|---|---|
| Redis or Valkey | Backend for the optional decision-list cache |
| `moselwal/keyvalue-store` | Composer package that exposes a Redis/Valkey connection through a stable `KeyValueStoreInterface` |

The extension runs perfectly without the optional cache: it falls back to direct LAPI calls
on every request.

---

## Installation

```bash
composer require koh/crowdsec-bridge
vendor/bin/typo3 extension:setup
```

Optionally, to enable the decision-list cache:

```bash
composer require moselwal/keyvalue-store
vendor/bin/typo3 cache:flush
```

---

## Configuration

### Bouncer API key

Generate a bouncer API key inside the CrowdSec container:

```bash
cscli bouncers add typo3-bridge
```

The bridge looks up the key in this exact order:

1. Docker secret file `/run/secrets/crowdsec_api_key` (recommended for production —
   SOPS-encrypted and mounted as a Docker secret).
2. Environment variable `CROWDSEC_LAPI_KEY`.
3. `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crowdsec_bridge']['lapiKey']` (development
   only — never use in production).

If none of these resolves, the module surfaces a clear configuration error on first access.

### Machine account

**The bouncer key alone is not enough for three of the four features.** CrowdSec puts
`GET /v1/decisions` behind its API-key middleware and everything else this module calls —
`/v1/heartbeat`, `/v1/alerts`, `/v1/alerts/{id}` and `DELETE /v1/decisions/{id}` — behind
the JWT middleware, which answers a bouncer key with **401**. Creating a decision goes to
`POST /v1/alerts`, which is a watcher route as well. The split is not configurable: there
is no way to grant a bouncer key access to those routes.

The module therefore logs in as a **machine (watcher) account** for those calls, exactly
as `cscli` does. Register one inside the CrowdSec container:

```bash
cscli machines add typo3-bridge --password '<generated>' --auto
```

The credentials are looked up in the same order as the bouncer key, and the two are
independent — the id and the password must both resolve or the account counts as absent:

1. Docker secret files `/run/secrets/crowdsec_machine_id` and
   `/run/secrets/crowdsec_machine_password` (recommended for production).
2. Environment variables `CROWDSEC_LAPI_MACHINE_ID` and `CROWDSEC_LAPI_MACHINE_PASSWORD`.
3. `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crowdsec_bridge']['machineId']` and
   `[...]['machinePassword']` (development only).

Deliberately **not** in the extension configuration: it is a password, and Constitution
principle IV keeps secrets out of the settings table.

**Without a machine account the module still works, partly.** The decisions tab keeps
listing and quick-checking, because that is bouncer territory. The status panel, the
alerts tab and adding or deleting a decision report that no machine account is configured.
Nothing silently returns an empty list.

The JWT is fetched on first use and kept in memory until CrowdSec's own expiry, and it is
exchanged once and the call retried if CrowdSec refuses it — a token that outlives a
FrankenPHP worker process is a normal event, a credential refused twice is not.

### Extension configuration

Manage these values via **Admin Tools → Settings → Extension Configuration → `crowdsec_bridge`**.

| Option | Type | Default | Description |
|---|---|---|---|
| `lapiBaseUrl` | string | `http://crowdsec:8080` | Absolute http(s) URL of the CrowdSec Local API. Must be reachable from the TYPO3 container. The environment variable `CROWDSEC_LAPI_BASE_URL`, when set, takes precedence; an unparseable value counts as unset. |
| `lapiTimeout` | int | `5` | Timeout in seconds for every LAPI call, connect and read. Valid range 1–120; out-of-range values fall back to the default (below) or are capped (above). |
| `allowDeleteForeignDecisions` | bool | `0` | When `1`, **TYPO3 admins** (not regular operators) may also delete decisions created by CrowdSec or `cscli`. Regular `crowdsec_operator` members are never allowed to delete foreign decisions regardless of this setting. |
| `cacheEnabled` | bool | `1` | Enable the decision-list cache. Requires `moselwal/keyvalue-store` to be installed; without it this setting has no effect. |
| `cacheTtl` | int | `10` | Cache lifetime in seconds. Valid range 1–300; out-of-range values are clamped at construction time and a notice is logged. |

---

## Backend permissions

The bridge does not auto-create backend groups. Create them once in **System → Backend Users
→ Groups** and assign module access.

| Group | Role | Allowed actions |
|---|---|---|
| `crowdsec_viewer` | Read-only | View decisions list, view alerts, run IP quick-check, see LAPI status |
| `crowdsec_operator` | Operator | All viewer rights, plus add new decisions and delete own `typo3`-origin decisions |
| TYPO3 admin (`$BE_USER->isAdmin() === true`) | Admin | All operator rights, plus delete foreign-origin decisions when `allowDeleteForeignDecisions=1` |

All access checks happen server-side. The UI hides buttons that the current user is not
permitted to use; the controller additionally enforces every check on the request handler so
that direct URL manipulation results in HTTP 403.

---

## Usage

### View decisions

Open **System → CrowdSec**. The Decisions tab is the default. Use the filter form to narrow
by scope, type or origin. Pagination preserves the active filter.

### Quick-check an IP

Enter an IP (IPv4 or IPv6, including abbreviated forms like `::1`) in the quick-check field
and submit. The bridge validates the input server-side first; invalid values never reach
LAPI. The result panel shows decision ID and remaining time on a hit.

Every quick-check produces a `sys_log` entry of type `crowdsec_bridge:quickcheck` containing
the user, timestamp, queried IP, and result.

### Add a decision

Operators see an **Add decision** button. The form requires scope, type, value, duration and
reason. The server validates everything before issuing the LAPI POST. The new decision always
gets `origin = "typo3"` — this cannot be overridden from the form.

If you submit the form twice in quick succession, the server-side submit-token catches the
second request as `crowdsec_bridge:duplicate_submit` and shows a flash message.

### Delete a decision

Click **Delete** on a `typo3`-origin row. A native TYPO3 modal confirms the action with the
decision's ID, scope and value rendered for verification. After confirmation the row is
removed; the audit log records who removed it and when.

Foreign-origin rows show **Foreign origin — protected** instead of a delete button. Only
TYPO3 admins (with `allowDeleteForeignDecisions=1`) see a danger-styled delete button on
those rows; the modal then carries an extra warning explaining what is about to be
overridden.

### Inspect alerts

Switch to the **Alerts** tab. Filter by source scope or scenario substring. Click any alert
ID to open its detail view, which shows the full source object, labels and up to 50 linked
decisions. Direct URLs (e.g. `…/system_crowdsec/alertDetail?id=42`) are bookmarkable and
work without first visiting the list.

---

## Architecture

Strict DDD 4-layer split, enforced via `deptrac.yaml`:

```text
Classes/
├── Domain/           # Pure PHP value objects, enums, contracts. Zero external dependencies.
├── Application/      # CQRS queries and commands plus their handlers. May depend only on Domain.
├── Infrastructure/   # HTTP client, repositories, audit loggers, cache decorator. May depend on
│                     # Application, Domain and framework types.
└── Presentation/     # Backend controller, Fluid templates, event listeners.
```

Deptrac forbids upward imports and is part of the CI gate. Domain code is framework-free and
trivially unit-testable; the HTTP client implements `Psr\Http\Client\ClientInterface` and is
injected via constructor.

The CQRS split is shallow but consistent: every backend interaction goes through a
`*Query` / `*Command` plus its dedicated `*Handler`. Handlers receive their dependencies via
constructor injection (`final readonly` everywhere).

The optional cache is a **decorator** over `LapiDecisionRepository` that is only wired in
the dependency-injection container when `moselwal/keyvalue-store` is installed and
`cacheEnabled=1` (see `Configuration/Services.php`). When the package is absent, the default
`NullDecisionCacheInvalidator` no-op binding ensures the write handlers do not need any
runtime branching.

---

## Security model

The bridge applies defence-in-depth against accidental and malicious writes.

| Layer | Mechanism |
|---|---|
| Module visibility | TYPO3 module-access ACL hides the module from users without the assigned group |
| Controller entry | Every action calls `OperatorPermissionsFactory::build()` and rejects requests that fail the relevant `assertCan*` |
| CSRF | Every write endpoint validates the TYPO3 backend form token; missing or invalid tokens result in HTTP 403 and a `crowdsec_bridge:csrf_denied` audit entry |
| Double-submit | Add and delete forms include a server-stored submit token, consumed on first POST; a duplicate triggers `crowdsec_bridge:duplicate_submit` |
| Input validation | All values are normalised and validated in domain value objects (`IpAddress`, `Duration`, `DecisionScope`, `DecisionType`, `Reason`) before any LAPI call |
| Foreign-origin protection | `OperatorPermissions::assertCanDelete(Decision)` enforces origin rules server-side. Foreign origins require admin status **and** the `allowDeleteForeignDecisions=1` config flag |
| Rate limiting | The backend module never issues more than one writing LAPI call per HTTP request (no bulk endpoints — operators wanting batch operations must use `cscli`) |

No secret material is ever rendered into the browser. The bouncer API key lives in PHP-side
state only, sourced from a Docker secret, environment variable, or `TYPO3_CONF_VARS` (in
that order).

---

## Audit trail (sys_log)

The bridge writes structured entries to `sys_log` using these `type` values. Filter the
**System → Log** module by these to inspect activity.

| `type` | When written | Channel | Severity |
|---|---|---|---|
| `crowdsec_bridge:quickcheck` | On every IP quick-check (success or miss) | `security` | `info` |
| `crowdsec_bridge:decision_add` | Successful or failed add attempts | `security` | `info` / `warning` |
| `crowdsec_bridge:decision_delete` | Successful, not-found, or failed delete attempts | `security` | `info` / `warning` |
| `crowdsec_bridge:write_denied` | Write attempt by a user without the operator role | `security` | `warning` |
| `crowdsec_bridge:delete_denied` | Delete attempt against a foreign-origin row without admin override | `security` | `warning` |
| `crowdsec_bridge:csrf_denied` | Write attempt with missing or invalid form token | `security` | `warning` |
| `crowdsec_bridge:duplicate_submit` | Re-submission of an already-consumed form token | `security` | `warning` |

The full audit context (action, status, decision data, original origin, latency, error message)
is JSON-serialised into the `log_data` column for downstream analysis.

---

## Optional cache (keyvalue-store)

When `moselwal/keyvalue-store` is installed and `cacheEnabled=1`, the bridge caches paginated
decision-list responses with a short TTL (default 10 s) — typical impact: repeated F5
reloads stay below 50 ms.

**Invalidation** uses a generation-counter pattern (atomic `INCR` on a single KVS key). After
every successful add or delete the counter is incremented, which makes all previously cached
keys unreachable in O(1) without scanning. Old entries expire via TTL.

**Failure handling**: any KVS error (backend down, connection timeout, malformed payload)
silently degrades to direct LAPI calls. The first failure of a request is logged once at
warning level into the TYPO3 logger; subsequent failures within the same request are
suppressed to avoid log spam (FR-010).

**What is not cached**:

- `findById` — every individual decision lookup goes straight to LAPI to keep the delete-
  origin-snapshot honest.
- `findByIp` (quick-check) — operators expect this to reflect the live LAPI state.
- LAPI heartbeat — the status banner must always be current.
- Alerts — alerts are CrowdSec-internal and not cached in this release.

---

## Localisation

All UI strings live in `Resources/Private/Language/locallang.xlf` and
`locallang_module.xlf`. Default language is German. Additional translations can be added by
duplicating the XLF files with the appropriate locale prefix (e.g.
`en.locallang.xlf`) and registering them through standard TYPO3 mechanisms — no code changes
required.

---

## Docker compose example

A minimal compose snippet that wires the bridge to a CrowdSec container and an optional
Redis cache:

```yaml
services:
  app:
    image: typo3:14
    depends_on:
      - crowdsec
      - redis
    networks:
      - internal
    secrets:
      - crowdsec_api_key
      - crowdsec_machine_id
      - crowdsec_machine_password
    environment:
      CROWDSEC_LAPI_KEY_FILE: /run/secrets/crowdsec_api_key

  crowdsec:
    image: crowdsecurity/crowdsec:latest
    networks:
      - internal
    expose:
      - "8080"

  redis:
    image: redis:7-alpine
    networks:
      - internal
    expose:
      - "6379"

networks:
  internal:

secrets:
  crowdsec_api_key:
    file: ./secrets/dev/crowdsec_api_key
  # Without these two, the status panel, the alerts tab and every write report
  # that no machine account is configured — see "Machine account" above.
  crowdsec_machine_id:
    file: ./secrets/dev/crowdsec_machine_id
  crowdsec_machine_password:
    file: ./secrets/dev/crowdsec_machine_password
```

Production setups should encrypt the secret file with SOPS + age, mount through Docker
secrets, and avoid exposing either Redis or CrowdSec ports outside the internal network.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Module is missing from the System submenu | Backend group lacks module access | In **System → Backend Users → Groups** enable the **System: CrowdSec** module entry |
| Status banner reads *No machine account configured* | The watcher credentials do not resolve — id or password missing from every lookup source | Register one with `cscli machines add typo3-bridge --password '<generated>' --auto` and mount both credentials — see [Machine account](#machine-account) |
| Status banner reads *Authentication failed*, decisions list still works | CrowdSec refused the machine account: wrong id or password, or the machine was never validated | The heartbeat is a watcher route, so this is always about the machine account, never the bouncer key. Check the mounted credentials against `cscli machines list` |
| Alerts tab empty and adding a decision fails, while the decisions list works | Same cause: those are all watcher routes | As above |
| Status banner reads *Connected* but the decisions list shows *Data could not be loaded* | Bouncer API key invalid or missing — LAPI answers 403, which the module reports as a failed load | Generate a fresh key with `cscli bouncers add typo3-bridge` and update the configured secret source |
| Status banner reads *Not reachable* | LAPI container down, network blocked, or wrong base URL | Verify `CROWDSEC_LAPI_BASE_URL` (or, without it, `lapiBaseUrl`) and run `curl http://crowdsec:8080/health` from the TYPO3 container (`/v1/heartbeat` needs a watcher token and would answer 401 even on a healthy LAPI) |
| Delete button missing on a `typo3`-origin row | Current user is not in `crowdsec_operator` (or admin) | Add the user to the operator group or grant TYPO3 admin status |
| Delete button missing on a foreign-origin row | Expected for non-admins; for admins the override flag is off | Set `allowDeleteForeignDecisions=1` for admins, or use `cscli decisions delete` |
| Modal does not open when clicking *Delete* | Backend JavaScript modules not flushed | Run `vendor/bin/typo3 cache:flush` |
| Quick-check shows *Banned* but list is empty | Cache holds an outdated empty list while a fresh decision is already in LAPI | Either wait for the TTL (default 10 s) or temporarily set `cacheEnabled=0` |
| Form rejects valid duration like `1h30m` | Validation only allows the duration presets and free-form Go-style values | Use a preset, or write the value in Go-duration form (`1h`, `7d`, `1h30m`, etc.) |
| sys_log fills with cache warnings | Cache backend down for the entire request; once-per-request guard ensures only one log entry per failing request | Restart Redis / fix the backend; the module will re-engage cache automatically next request |

---

## Tests and quality gates

```bash
composer install
composer phpstan           # PHPStan level 8 with strict-rules
composer test              # PHPUnit unit suite
composer test:functional   # PHPUnit functional suite (PSR-18 mocks, no real CrowdSec)
composer deptrac           # DDD layer compliance
composer cs-check          # PER-CS3x0 + Symfony coding standard (read-only check)
composer cs-fix            # Same, but autofixes
```

CI runs all of them on every change, plus a contract suite against a real CrowdSec LAPI
(`composer test:contract`, needs a running LAPI; see `Tests/ContractTests.xml`).

---

## Versioning and changelog

Semantic versioning; tags follow `vMAJOR.MINOR.PATCH`, and [`CHANGELOG.md`](CHANGELOG.md)
records every release. Up to 0.9.9 the package was released (internally) as `moselwal/crowdsec-bridge`
with the PHP namespace `Moselwal\CrowdsecBridge`; 0.10.0 is the same code under
`koh/crowdsec-bridge` and `Koh\CrowdsecBridge`. The extension key `crowdsec_bridge`, its
tables and its backend permissions did not change. High-level milestones:

| Tag | Headline |
|---|---|
| `v0.1.0` | Decisions list, LAPI heartbeat banner, IP quick-check with read-audit |
| `v0.2.0` | Decision add/delete with CSRF, double-submit, origin-protection, full write-audit |
| `v0.3.0` | Alerts tab — paginated read-only list with case-insensitive scenario filter, bookmarkable detail view |
| `v0.4.0` | Optional generation-counter-based cache via `moselwal/keyvalue-store` with O(1) invalidation |
| `v0.10.0` | Renamed to `koh/crowdsec-bridge` / `Koh\CrowdsecBridge` |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). The GitHub repository is a read-only mirror;
accepted pull requests are applied upstream with authorship and sign-off intact. Security
reports go through [SECURITY.md](SECURITY.md), never a public issue.

The DDD split is taken seriously: keep a change within one layer per commit where possible,
and test domain and application changes at the unit level.

---

## License

MIT. See [`LICENSE`](LICENSE).
