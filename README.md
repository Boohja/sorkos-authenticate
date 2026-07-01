# Sorkos Auth

Central authentication service for Sorkos and Comasu projects.

## Configuration

Copy `app/config/local.example.php` to `app/config/local.php` and override the values needed for the environment.

Every config entry added to `app/config/config.php` or `app/config/local.example.php` must also be documented in this section.

### `app`

| Key | Purpose |
| --- | --- |
| `name` | Human-readable app name. |
| `env` | Environment label, for example `local` or `production`. |
| `debug` | Enables verbose debug output when true. Keep false in production. |
| `debug_footer` | Shows request, PHP session, pending authorize, email challenge, and auth-session lookup details in the page footer when true. Intended for local flow debugging only. |
| `timezone` | PHP timezone used for generated timestamps. |
| `base_url` | Public base URL used as a fallback when request headers do not provide the current origin. |
| `auth_secret` | App-wide cryptographic secret used for HMAC hashing of email login codes. Use a long random value in production. |

### `db`

| Key | Purpose |
| --- | --- |
| `enabled` | Enables database-backed features when true. |
| `host` | Database host. |
| `port` | Database port. |
| `database` | Database name. |
| `username` | Database user. |
| `password` | Database password. |
| `charset` | Database charset, defaults to `utf8mb4`. |

### `tasks`

| Key | Purpose |
| --- | --- |
| `housekeeping_secret` | Secret for the scheduled cleanup endpoint. Prefer a separate long random value from `auth_secret`. |
| `email_code_retention_hours` | How long expired or consumed email login codes are retained before cleanup can delete them. |
| `authorization_code_retention_hours` | How long expired or used OAuth authorization codes are retained before cleanup can delete them. |
| `session_retention_days` | How long expired or revoked auth sessions are retained before cleanup can delete them. |

Run cleanup from cron, Windows Task Scheduler, or an external scheduler by calling:

```bash
curl -X POST https://auth.example.test/internal/cleanup \
  -H "X-Housekeeping-Secret: your-housekeeping-secret"
```

Or simply call the endpoint with appended `?secret=...`

## Development

Install project dependencies locally:

```bash
composer install
```

Run the test suite:

```bash
composer test
```

Live database integration tests are skipped by default because they write rows.
They use unique `pest_*` markers and clean up matching clients, users, codes,
sessions, identities, and redirect URIs after each test.

Run them only against a database where these temporary rows are acceptable:

```powershell
$env:SORKOS_LIVE_DB_TESTS='1'
vendor\bin\pest.bat tests\Integration
```
