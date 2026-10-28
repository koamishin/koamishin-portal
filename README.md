# Koamishin Portal

Koamishin Portal is a Laravel application with a Vue/Inertia user interface and a Filament administration panel. It includes account management, Passport OAuth clients, tenant and application records, and optional social sign-in. This repository also contains a Blog module and local client package source trees.

The current lockfile includes Laravel 13, Filament 5, Passport 13 and Inertia 3. PHP 8.5 and Node 22.18 or later in the 22.x series are suitable for the project's tooling; Composer declares PHP `^8.3`. See the [installation guide](docs/getting-started/installation.mdx) and lockfiles for the detailed requirements.

> **Before deploying:** the default database seeder creates accounts with known passwords. The bundled Compose configuration contains placeholder secrets and needs further work before production. See [deployment and operations](docs/guides/ci-cd.mdx).

## Get started

Follow [installation](docs/getting-started/installation.mdx) for a fresh local instance and [configuration](docs/getting-started/configuration.mdx) for service settings. Start development with `composer run dev` after installation. The first-run wizard is at `/setup` and the administration panel is at `/admin`.

- [Architecture and limitations](docs/architecture.mdx)
- [Tenancy and OAuth integration](docs/guides/core-concepts.mdx)
- [Laravel SDK integration](docs/guides/laravel-sdk.mdx)
- [User metadata](docs/guides/user-metadata.mdx)
- [Deployment and operations](docs/guides/ci-cd.mdx)
- [Astro landing development and deployment](docs/guides/landing.mdx)
- [Security](docs/guides/security.mdx)
- [Development and contributing](CONTRIBUTING.md)

The documentation also has a browsable Astro site. Install its dependencies with `npm ci --prefix landing` and start it with `npm run dev --prefix landing`; the server prints the local address. See the [landing guide](docs/guides/landing.mdx) to build and deploy it.

The project is licensed under the [MIT License](LICENSE).
