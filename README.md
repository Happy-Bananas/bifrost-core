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
| `Bifrost\Core\Lock\FileIntegrationLock` | The run lock: only one integration command runs at a time, so scheduled runs never overlap. |
| `Bifrost\Core\RuntimeDirectory` | Where the integration keeps files that must survive between runs: `TRACEZILLA_RUNTIME_DIR`, or `var/`. |

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
