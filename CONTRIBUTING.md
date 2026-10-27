# Contributing

Issues and contributions should describe the problem, expected behavior, and a reproducible example where possible. Review the existing code and project instructions in [AGENTS.md](AGENTS.md), follow nearby conventions, and keep changes focused. Do not include credentials, private keys, database files, or personal data in contributions.

Set up a local instance using the [installation guide](docs/getting-started/installation.mdx). The following checks are available; run those relevant to your change:

```bash
composer test:lint
composer phpstan
composer rector
php artisan test --compact
npm run check
npm run types:check
```

`composer rector` is a dry run; `composer rector:fix` applies changes. `composer lint` formats PHP. `composer test` runs the PHP formatting check and tests; `composer ci` additionally runs PHPStan and the Rector dry run. Tests use Pest. Add meaningful coverage for changed behavior and its important failure cases. The GitHub Actions workflows in `.github/workflows/` are the source of truth for their triggers and checks; some formatting jobs can commit changes.

For security-sensitive reports, avoid publishing secrets or exploitable details in a public issue. Contact the project maintainers privately using an established channel; this repository does not specify a dedicated security reporting address.
