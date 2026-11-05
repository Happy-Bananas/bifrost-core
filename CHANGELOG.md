# Changelog

What changed in each release, and what an integration has to change to use it.

## 0.6.0 (unreleased)

The seed needs no settings of its own any more.

- Removes `SeedTeamGuard` and the `TRACEZILLA_SEED_ALLOWED_TEAM` setting from
  0.4.0. The extra setting made the seed harder to start with, and a
  production project is already protected: it is created with
  `--without-seed`, so it has no seed command, and every seed run should start
  with a dry run, which names the team first.
- Removes the `TRACEZILLA_OWN_COMPANY` setting. The seed now uses partner
  number 1 as the own company: tracezilla creates it together with every
  company. Its name is shown in the seed's output, for example
  `partner own_company exists Banana McShopface (partner number 1)`.

What an integration changes: remove `TRACEZILLA_SEED_ALLOWED_TEAM` and
`TRACEZILLA_OWN_COMPANY` from `.env.example` and `.env`. Nothing else.

## 0.5.0 (2026-11-05)

New: `Bifrost\Core\Lock\RunLock::takeOrExit($projectDirectory, $command)`.
A command calls it before it reads or writes. It takes the integration's run
lock for the rest of the process, or, if another command holds it, explains
that and stops with exit code 75.

Before, only `bin/bifrost-connect` took the lock, so a command started
directly (`php bin/scenario-run ...`) ran without it, and a killed
`bin/bifrost-connect` released the lock while its command was still running.
Now the command that does the work holds the lock.

`tracezilla:seed` (`SeedCommand`) now takes the lock itself.

What an integration changes: call `RunLock::takeOrExit()` in each command
that must not overlap with another, and stop taking the lock in
`bin/bifrost-connect`.

## 0.4.0 (2026-11-05)

The tracezilla seed moves here from the integration templates: `SeedCommand`,
`Seeder`, `SeedDefinition`, `SeedFileValidator`, `SeedGateway`,
`TracezillaSeedGateway` and `SeedResult` under `Bifrost\Core\Seed`, and the
standard test dataset in `seeds/tracezilla.yaml`.

New: `tracezilla:seed --execute` writes only into the team named by
`TRACEZILLA_SEED_ALLOWED_TEAM` in `.env`. Without it, or with another team,
the command stops with exit code 2 before contacting tracezilla. A dry run
needs nothing.

What an integration changes:

- Replace `bin/seed-tracezilla` with the short version that runs
  `Bifrost\Core\Seed\SeedCommand`, and delete `src/Seed/`, `tests/Unit/Seed/`
  and `seeds/` (unless the project changed its seed file; keep that file).
- In a development project, add `TRACEZILLA_SEED_ALLOWED_TEAM=<team slug>` to
  `.env`. Never add it for a customer's production team.

## 0.3.0 (2026-11-05)

The tracezilla client sends a request again when tracezilla cannot handle it
for the moment, so a short outage or rate limit no longer fails a whole run.

- Every request is sent again after HTTP 429 (too many requests), because
  tracezilla did not handle it.
- A GET is also sent again after HTTP 500, 502, 503 or 504, or when tracezilla
  could not be reached. A POST, PUT or DELETE is not: it may already have been
  carried out, and sending it twice could create a record twice.
- Up to 3 retries, waiting 1, 2 and 4 seconds, or as long as tracezilla's
  `Retry-After` header asks, but never more than 60 seconds.
- Each retry writes one line to the error output, for example
  `GET /skus: tracezilla answered HTTP 503. Trying again in 1 second.`, so it
  shows in the cron log.

What an integration changes: require `^0.3`. Nothing else.

## 0.2.0 (2026-11-05)

The tracezilla client and the OAuth tokens move here from the WooCommerce
integration template: `Client`, `TracezillaConfiguration`, `TokenProvider`,
`TokenStore`, `TokenFileLock`, `OAuthTokens`, `Clock`, `SystemClock` and
`SkuCreator`, under `Bifrost\Core\Tracezilla`.

Changes:

- The token file is `tracezilla-token.json` in the runtime directory
  (`TRACEZILLA_RUNTIME_DIR`, or `var/`). `TRACEZILLA_TOKEN_FILE` still names
  another file. Before, the WooCommerce template ignored
  `TRACEZILLA_RUNTIME_DIR` for the token.
- Saving the tokens writes a temporary file that only the owner can read from
  the start, with a name no other run uses, and fails loudly when the file
  cannot be replaced. Before, the temporary file was briefly readable by
  others and a failed rename went unnoticed.
- The token lock file is readable only by the owner.

What an integration changes:

- Import `Bifrost\Core\Tracezilla\…` instead of its own copies, and delete them.
- If `.env` sets `TRACEZILLA_RUNTIME_DIR` to anything but `var` and has no
  `TRACEZILLA_TOKEN_FILE`, move the token file into the runtime directory, or
  run `tracezilla:login` again.

## 0.1.0 (2026-11-05)

First release: `WriteMode`, the run lock (`FileIntegrationLock`,
`AcquiredIntegrationLock`) and `RuntimeDirectory`, taken from the WooCommerce
integration template. The namespace is `Bifrost\Core`.
