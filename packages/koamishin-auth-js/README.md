# Koamishin Auth JavaScript client

This directory contains a browser-oriented TypeScript client for the Portal's Passport authorization-code flow with PKCE. The package is published as [@koamishin/auth-js on npm](https://www.npmjs.com/package/@koamishin/auth-js):

```bash
npm install @koamishin/auth-js
```

The published package can differ from the source in this checkout. Review the version you install and test the complete integration before deploying it; this checkout's package entry points reference TypeScript source and no build script is defined here.

`createKoamishinClient` accepts `portalUrl`, `clientId`, `redirectUri` and optional `scopes`. `redirectToSignIn()` stores state and a PKCE verifier in `sessionStorage` and redirects to the hosted application login. `redirectToSignUp()` starts the same transaction with `screen_hint=signup` for the hosted registration form. On the registered callback page, `handleRedirectCallback()` validates state, exchanges the code at `/oauth/token`, stores tokens in session storage and retrieves `/api/oauth/userinfo`. `getUser()` retrieves userinfo, and `refreshToken()` requests a new access token. `signOut()` removes this client's locally stored access and refresh tokens; it does not revoke them or end the Portal session.

Browser storage is scoped by Portal URL and client ID so clients on the same origin do not share tokens or sign-out state. This changes the storage keys used by earlier versions: existing browser sessions must sign in again after upgrading. Clear the old unscoped `koamishin_access_token` and `koamishin_refresh_token` keys during migration if they exist. A Portal login or another application session does not skip the hosted login; an external social provider may reuse its own session.

The implementation uses `window`, `sessionStorage` and Web Crypto and is intended to execute in a browser, not during server-side rendering. Use a public Passport client and exact registered redirect URI, configure any necessary cross-origin policy, and account for the security implications of storing tokens in browser-accessible storage. The Portal currently does not issue OIDC ID tokens; do not assume OIDC conformance. See the Portal's tenancy and OAuth documentation for endpoint and scope limitations.

MIT licensed; see [LICENSE](LICENSE).
