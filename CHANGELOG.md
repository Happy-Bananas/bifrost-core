# Changelog

What changed in each release, and what an integration has to change to use it.

## 0.2.0 (unreleased)

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
