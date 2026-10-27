# Koamishin Auth JavaScript client

This directory contains a browser-oriented TypeScript client for the Portal's Passport authorization-code flow with PKCE. Registry publication and end-to-end compatibility are not established by the source in this checkout. Review and test the integration before deploying it; the package entry points reference TypeScript source and no build script is defined here.

`createKoamishinClient` accepts `portalUrl`, `clientId`, `redirectUri` and optional `scopes`. `redirectToSignIn()` stores state and a PKCE verifier in `sessionStorage` and redirects to `/oauth/authorize`. On the registered callback page, `handleRedirectCallback()` validates state, exchanges the code at `/oauth/token`, stores tokens in session storage and retrieves `/api/oauth/userinfo`. `getUser()` retrieves userinfo, and `refreshToken()` requests a new access token. `signOut()` removes the locally stored access and refresh tokens; it does not revoke them or end the Portal session.

The implementation uses `window`, `sessionStorage` and Web Crypto and is intended to execute in a browser, not during server-side rendering. Use a public Passport client and exact registered redirect URI, configure any necessary cross-origin policy, and account for the security implications of storing tokens in browser-accessible storage. The Portal currently does not issue OIDC ID tokens; do not assume OIDC conformance. See the Portal's tenancy and OAuth documentation for endpoint and scope limitations.

MIT licensed; see [LICENSE](LICENSE).
