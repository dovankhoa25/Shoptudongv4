import type { EchoOptions } from 'laravel-echo';

type MetaReader = (name: string) => string | null | undefined;

const text = (value: unknown): string => typeof value === 'string' ? value.trim() : '';
const publicKey = (value: unknown): string => text(value).split(':', 1)[0];
const record = (value: unknown): Record<string, unknown> => value && typeof value === 'object' ? value as Record<string, unknown> : {};

/** Inertia props also work when an older deployed Blade template has no meta tags. */
export function buildEchoConfig(realtime: unknown, readMeta: MetaReader, readCurrentRealtime = () => realtime): EchoOptions<'ably'> {
    const bootstrap = record(realtime);
    const csrfToken = text(bootstrap.csrf_token) || text(readMeta('csrf-token')) || null;
    return {
        broadcaster: 'ably',
        key: publicKey(bootstrap.public_key) || publicKey(readMeta('ably-public-key')),
        csrfToken,
        authEndpoint: '/broadcasting/auth',
        // SPA login rotates the CSRF token. Resolve headers at authorization
        // time without recreating Echo or its existing websocket channels.
        channelAuthorization: {
            endpoint: '/broadcasting/auth',
            transport: 'ajax',
            headersProvider: () => {
                const currentToken = text(record(readCurrentRealtime()).csrf_token) || csrfToken;
                return currentToken ? { 'X-CSRF-TOKEN': currentToken } : {};
            },
        },
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
    };
}
