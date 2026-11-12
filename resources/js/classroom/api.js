// Every call to the classroom JSON API (adapted from Dot.Engage's meeting api.js): CSRF, the per-tab client id,
// tolerant JSON parsing, and errors that always carry a status, the server's error code and a plain message.

export class ApiError extends Error {
    constructor(status, message, data = null, retryAfter = 0) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
        this.code = data && typeof data.error === 'string' ? data.error : null;
        this.retryAfter = retryAfter;
    }

    get network() { return this.status === 0; }
    get displaced() { return this.code === 'displaced'; }
    get notJoined() { return this.code === 'not_joined'; }
}

export function createApi({ clientId }) {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    async function request(method, url, body, { keepalive = false, timeoutMs = 15000, form = false } = {}) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        let response;
        try {
            response = await fetch(url, {
                method,
                credentials: 'same-origin',
                cache: 'no-store',
                keepalive,
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Client-Id': clientId,
                    ...(body === undefined || form ? {} : { 'Content-Type': 'application/json' }),
                },
                body: body === undefined ? undefined : (form ? body : JSON.stringify(body)),
            });
        } catch (e) {
            throw new ApiError(0, e && e.name === 'AbortError' ? 'The server took too long to answer.' : 'You appear to be offline.');
        } finally {
            clearTimeout(timer);
        }

        let data = null;
        try {
            const text = await response.text();
            data = text ? JSON.parse(text) : null;
        } catch (e) {
            data = null;
        }

        if (!response.ok) {
            const retry = parseInt(response.headers.get('Retry-After') || '0', 10) || 0;
            const fallback = {
                419: 'Your session expired. Reload this page to continue.',
                429: 'Too many requests. Slowing down.',
                401: 'You are signed out. Reload this page to continue.',
                403: 'You do not have access to this.',
            }[response.status] || `The server answered ${response.status}.`;
            const message = data && typeof data.message === 'string' && data.message ? data.message : fallback;
            throw new ApiError(response.status, response.status === 419 ? fallback : message, data, retry);
        }

        return data && typeof data === 'object' ? data : {};
    }

    return {
        post: (url, body = {}, opts) => request('POST', url, body, opts),
        upload: (url, formData, opts) => request('POST', url, formData, { ...opts, form: true, timeoutMs: 60000 }),
    };
}
