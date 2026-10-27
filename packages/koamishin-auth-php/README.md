# Koamishin Auth PHP client

This directory contains Laravel-oriented client source for the Portal's Passport authorization-code flow with PKCE. The package is published as [koamishin/auth-php on Packagist](https://packagist.org/packages/koamishin/auth-php):

```bash
composer require koamishin/auth-php
```

The published package can differ from the source in this checkout. Review the version you install and test the complete integration before deployment. This checkout's Composer manifest declares PHP 8.2–8.5 and Illuminate 10–13 version constraints; those constraints are not a guarantee that every combination has been tested.

`KoamishinAuth::getLoginUrl()` stores OAuth state and a PKCE verifier in the Laravel session and returns an authorization URL. `handleCallback($code, $state)` checks state, exchanges the code using the configured client secret, fetches `/api/oauth/userinfo`, and stores the user claims and access token in the session. `user()` and `check()` read the local session. `logout()` clears those local values; it does not revoke tokens or end the Portal session. Protect session storage and the client secret.

The package includes a role-checking middleware and `KoamishinWebhookValidator`, which validates the signed request headers and timestamp against the configured secret. Review the source and verify interoperability, replay handling, authorization and error handling in the receiving application. Portal userinfo is not tenant-scoped and currently returns roles and permissions irrespective of scopes. The Portal does not issue OIDC ID tokens, so do not assume OIDC conformance.

Configuration lives in `config/koamishin.php` and is registered by the package service provider. See the Portal's tenancy and OAuth documentation for endpoints, webhook behavior and integration limits.

MIT licensed; see [LICENSE](LICENSE).
