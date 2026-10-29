import { afterEach, expect, test, vi } from 'vitest';
import { createKoamishinClient } from './index';

afterEach(() => {
    vi.unstubAllGlobals();
});

test('application clients keep their transactions and sessions separate', async () => {
    const storage = new Map<string, string>();
    vi.stubGlobal('sessionStorage', {
        getItem: (key: string) => storage.get(key) ?? null,
        setItem: (key: string, value: string) => storage.set(key, value),
        removeItem: (key: string) => storage.delete(key),
    });
    const location = { href: '', search: '' };
    vi.stubGlobal('window', { location });
    const fetchMock = vi.fn(async (url: string, options: RequestInit) => {
        if (url.endsWith('/oauth/token')) {
            const code = (options.body as URLSearchParams).get('code');
            return {
                ok: true,
                json: async () => ({
                    access_token: `token-${code}`,
                    expires_in: 60,
                }),
            };
        }

        const token = (options.headers as Record<string, string>).Authorization;
        return { ok: true, json: async () => ({ sub: token }) };
    });
    vi.stubGlobal('fetch', fetchMock);

    const appA = createKoamishinClient({
        portalUrl: 'https://portal.test',
        clientId: 'app-a',
        redirectUri: 'https://app.test/a/callback',
    });
    const appB = createKoamishinClient({
        portalUrl: 'https://portal.test',
        clientId: 'app-b',
        redirectUri: 'https://app.test/b/callback',
    });
    await appA.redirectToSignIn();
    const login = new URL(location.href);
    await appB.redirectToSignUp();
    const registration = new URL(location.href);

    expect(login.searchParams.has('screen_hint')).toBe(false);
    expect(registration.searchParams.get('screen_hint')).toBe('signup');
    expect(login.searchParams.get('code_challenge_method')).toBe('S256');
    expect(registration.searchParams.get('code_challenge_method')).toBe('S256');
    expect(login.searchParams.get('state')).not.toBe(
        registration.searchParams.get('state'),
    );

    location.search = `?code=wrong&state=${registration.searchParams.get('state')}`;
    await expect(appA.handleRedirectCallback()).rejects.toThrow(
        'OAuth state mismatch',
    );
    expect(fetchMock).not.toHaveBeenCalled();

    location.search = `?code=a&state=${login.searchParams.get('state')}`;
    await appA.handleRedirectCallback();
    location.search = `?code=b&state=${registration.searchParams.get('state')}`;
    await appB.handleRedirectCallback();

    expect((await appA.getUser()).sub).toBe('Bearer token-a');
    expect((await appB.getUser()).sub).toBe('Bearer token-b');
    await appA.redirectToSignIn();
    const pendingState = new URL(location.href).searchParams.get('state');
    appA.signOut();
    await expect(appA.getUser()).rejects.toThrow('No access token available');
    location.search = `?code=after-logout&state=${pendingState}`;
    await expect(appA.handleRedirectCallback()).rejects.toThrow(
        'OAuth state mismatch',
    );
    expect((await appB.getUser()).sub).toBe('Bearer token-b');
});
