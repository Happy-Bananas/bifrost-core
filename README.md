# BifrostConnect core

The shared core of [BifrostConnect](https://happy-bananas.github.io/tracezilla-integrations-docs/)
integrations between tracezilla and external services such as Shopify and
WooCommerce.

You don't install this package yourself. Every integration created with the
create command in
[Getting Started](https://happy-bananas.github.io/tracezilla-integrations-docs/getting-started.html)
requires it, and `composer install` downloads it into `vendor/`. Read the code
here to see how it works; change it here, not in a project's `vendor/`.

## What is in it

| Class | What it is for |
|:--|:--|
| `Bifrost\Core\WriteMode` | Whether a command only previews (`--dry-run`) or writes (`--execute`). There is no default. |
| `Bifrost\Core\Lock\RunLock` | The run lock every scheduled command takes with `RunLock::takeOrExit()`: only one runs at a time, and a command that finds it taken stops with exit code 75. |
| `Bifrost\Core\Lock\FileIntegrationLock` | The file lock underneath `RunLock`. |
| `Bifrost\Core\RuntimeDirectory` | Where the integration keeps files that must survive between runs: `TRACEZILLA_RUNTIME_DIR`, or `var/`. |
| `Bifrost\Core\Tracezilla\TracezillaConfiguration` | The tracezilla settings from `.env`. |
| `Bifrost\Core\Tracezilla\Client` | Calls the tracezilla API for one team, reads every page of a list, renews a rejected token once, and sends a request again after a temporary failure. |
| `Bifrost\Core\Tracezilla\TokenProvider` | The automatic OAuth login with the API user from `.env`, and the renewal of the access token. |
| `Bifrost\Core\Tracezilla\TokenStore` | Keeps the tokens in a file only the owner can read, `tracezilla-token.json` in the runtime directory. |
| `Bifrost\Core\Tracezilla\SkuCreator` | Creates a tracezilla SKU with exactly the units asked for. |
| `Bifrost\Core\Seed\SeedCommand` | The `tracezilla:seed` command: fills a development company with the test dataset in `seeds/tracezilla.yaml`. |

`seeds/tracezilla.yaml` is the standard test dataset. A project that needs
other test data puts its own file at `seeds/tracezilla.yaml` in the project,
which the seed then uses instead.

More of the code that the integrations share moves here over time.

## Versions

Releases follow [semantic versioning](https://semver.org/). Fixes and
additions that keep existing integrations working are minor or patch
releases. Anything that requires a change in an integration waits for the next
major version and is described in [CHANGELOG.md](./CHANGELOG.md). Before
version 1.0.0, a minor release (0.1 to 0.2) may still require changes.

An integration gets the latest compatible release with:

```bash
composer update happy-bananas/bifrost-core
```

## Development

The same Docker setup as every integration:

```bash
docker compose run --rm php composer install
docker compose run --rm php composer test
```

## License

This project is available under the [MIT License](./LICENSE).
