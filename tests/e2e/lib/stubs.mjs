// Local stand-ins for every external provider Dot.Tutor talks to. Nothing here contacts the internet and no real key
// exists: the app is pointed at these through its own env settings (run.mjs). Shapes follow the providers' documented
// APIs, the same ones the PHP fakes use (tests/Concerns/BuildsMoney.php, tests/Feature/InfoDotRedeemTest.php).
// Adapted from Dot.Ehail's R-46 harness (tests/e2e/lib/stubs.mjs there).
//
//  - edge (http):     /__control (read captures, switch behaviour) for the specs, and the app's HTTP(S)_PROXY: any
//                     attempt to reach a real host is recorded and refused, except a CONNECT to api.anthropic.com,
//                     which is tunnelled to the local AI stub when the AI stub is on.
//  - paystack (https, self-signed; PHP trusts only this certificate through curl.cainfo): transaction initialize and
//                     verify, refund (+ fetch), transferrecipient, transfer (+ verify). Webhooks are delivered to the
//                     app signed with the configured test secret (HMAC-SHA512 of the raw body, x-paystack-signature).
//  - ai (https, same certificate, SAN api.anthropic.com): POST /v1/messages answering in the Messages API shape.
//                     Its answers are labelled as stub output; it is a test double, never shown as real AI anywhere else.
//  - infodot (http on "localhost", a different origin from the app's 127.0.0.1): the launch page that posts a
//                     one-time token to /auth/ecosystem, and the redeem endpoint the app calls with its service secret.
//  - smtp:            a minimal SMTP server that captures every message (Laravel's smtp mailer, no TLS).
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import crypto from 'node:crypto';

export function createState() {
    return {
        paystack: { txs: {}, refunds: {}, transfers: {}, checkoutOutcome: 'success', calls: [], refundSeq: 9000 },
        infodot: { tokens: {}, redeems: [], secret: null, port: null },
        ai: { calls: [], refuse: false, port: null },
        webhooks: [], mails: [], outbound: [], appUrl: null, secret: null,
    };
}

function readBody(req) {
    return new Promise((res) => { const c = []; req.on('data', (d) => c.push(d)); req.on('end', () => res(Buffer.concat(c).toString('utf8'))); });
}
const send = (res, code, body, type = 'application/json') => { res.writeHead(code, { 'content-type': type }); res.end(typeof body === 'string' || Buffer.isBuffer(body) ? body : JSON.stringify(body)); };
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// ------------------------------------------------------------------ edge: control + proxy

export function startEdgeStub(state) {
    const server = http.createServer(async (req, res) => {
        if (/^https?:\/\//.test(req.url)) { state.outbound.push(new URL(req.url).host); return send(res, 403, 'e2e: the internet is off'); }
        const url = new URL(req.url, 'http://x');
        const raw = await readBody(req);
        if (url.pathname === '/__control') {
            if (req.method === 'POST') {
                const b = JSON.parse(raw || '{}');
                for (const k of ['checkoutOutcome']) if (k in b) state.paystack[k] = b[k];
                if ('aiRefuse' in b) state.ai.refuse = !!b.aiRefuse;
                return send(res, 200, { ok: true });
            }
            if (req.method === 'DELETE') { state.mails.length = 0; state.webhooks.length = 0; state.paystack.calls.length = 0; state.ai.calls.length = 0; return send(res, 200, {}); }
            return send(res, 200, { ...state, appUrl: undefined, secret: undefined, infodot: { redeems: state.infodot.redeems } });
        }
        if (url.pathname === '/__control/checkout') {
            const b = JSON.parse(raw || '{}');
            const out = await checkoutAction(state, b.code, b.action);
            return out ? send(res, 200, out) : send(res, 404, { error: 'unknown checkout' });
        }
        if (url.pathname === '/__control/redeliver') {
            // Paystack retries a webhook it thinks was not received: the same signed body again.
            const b = JSON.parse(raw || '{}');
            const tx = state.paystack.txs[b.reference];
            if (!tx) return send(res, 404, { error: 'unknown reference' });
            await deliverWebhook(state, 'charge.success', txData(tx));
            return send(res, 200, { webhooks: state.webhooks.filter((w) => w.reference === b.reference) });
        }
        if (url.pathname === '/__control/infodot-token') {
            const b = JSON.parse(raw || '{}');
            return send(res, 200, { token: mintInfoDotToken(state, b) });
        }
        return send(res, 404, { error: 'not_found' });
    });
    // The app's HTTPS_PROXY points here. api.anthropic.com goes to the AI stub; anything else is recorded and refused.
    server.on('connect', (req, sock, head) => {
        if (/^api\.anthropic\.com:443$/.test(req.url) && state.ai.port) {
            const up = net.connect(state.ai.port, '127.0.0.1', () => { sock.write('HTTP/1.1 200 Connection Established\r\n\r\n'); if (head?.length) up.write(head); up.pipe(sock); sock.pipe(up); });
            up.on('error', () => sock.destroy());
            sock.on('error', () => up.destroy());
            return;
        }
        state.outbound.push(req.url);
        sock.end('HTTP/1.1 403 Forbidden\r\n\r\n');
    });
    return listen(server);
}

// ------------------------------------------------------------------ Paystack, https

function txData(tx) {
    return {
        id: 5000000 + (parseInt(crypto.createHash('md5').update(tx.reference).digest('hex').slice(0, 6), 16) % 1000000), domain: 'test', status: tx.status, reference: tx.reference,
        amount: tx.amount, currency: tx.currency, channel: 'card', paid_at: tx.status === 'success' ? new Date().toISOString() : null, gateway_response: tx.status === 'success' ? 'Approved' : 'Declined',
        metadata: tx.metadata ?? null, customer: { email: tx.email, customer_code: 'CUS_e2e' }, fees: 100,
        authorization: { authorization_code: 'AUTH_e2e', bin: '408408', last4: '4081', exp_month: '12', exp_year: '2030', channel: 'card', card_type: 'visa', bank: 'TEST BANK', country_code: 'ZA', brand: 'visa', reusable: false, signature: 'SIG_e2e' },
    };
}

export async function deliverWebhook(state, event, data) {
    if (!state.appUrl) return;
    const body = JSON.stringify({ event, data });
    const sig = crypto.createHmac('sha512', state.secret).update(body).digest('hex');
    try {
        const r = await fetch(`${state.appUrl}/api/tutor/money/webhooks/paystack`, { method: 'POST', headers: { 'content-type': 'application/json', 'x-paystack-signature': sig }, body });
        state.webhooks.push({ event, reference: data.reference ?? data.transaction?.reference ?? null, status: r.status, body: (await r.text()).slice(0, 300) });
    } catch (e) { state.webhooks.push({ event, error: String(e.message) }); }
}

export function startPaystackStub(state, tls) {
    const ps = state.paystack;
    const server = https.createServer(tls, async (req, res) => {
        const url = new URL(req.url, 'https://x');
        const raw = await readBody(req);
        let body = {};
        try { body = raw ? JSON.parse(raw) : {}; } catch { /* ignore */ }
        // Paystack's hosted checkout page: Chrome resolves checkout.paystack.com to this server (run.mjs passes
        // --host-resolver-rules through E2E_CHROME_HOSTS), so the redirect after "Pay" lands here, never on the internet.
        if ((req.headers.host || '').startsWith('checkout.paystack.com')) {
            const [code, action] = url.pathname.split('/').filter(Boolean);
            const out = await checkoutAction(state, code, action || 'view');
            if (!out) return send(res, 404, 'unknown checkout', 'text/plain');
            if (!action) return send(res, 200, `<!doctype html><html lang="en"><title>Paystack test checkout (e2e stub)</title><h1>Pay R${(out.tx.amount / 100).toFixed(2)}</h1><p>${esc(out.tx.email)}</p><a id="pay" href="/${esc(code)}/pay">Pay</a> <a id="decline" href="/${esc(code)}/decline">Decline</a></html>`, 'text/html');
            res.writeHead(302, { location: out.location }); return res.end();
        }
        if ((req.headers.authorization || '') !== `Bearer ${state.secret}`) return send(res, 401, { status: false, message: 'Invalid key' });
        ps.calls.push({ method: req.method, path: url.pathname, body });
        let m;
        if (url.pathname === '/transaction/initialize' && req.method === 'POST') {
            const code = crypto.createHash('md5').update(body.reference).digest('hex').slice(0, 12);
            ps.txs[body.reference] = { reference: body.reference, amount: Number(body.amount), currency: body.currency || 'ZAR', email: body.email, metadata: body.metadata, callback_url: body.callback_url, access_code: code, status: 'pending' };
            return send(res, 200, { status: true, message: 'Authorization URL created', data: { authorization_url: `https://checkout.paystack.com/${code}`, access_code: code, reference: body.reference } });
        }
        if ((m = url.pathname.match(/^\/transaction\/verify\/(.+)$/))) {
            const tx = ps.txs[decodeURIComponent(m[1])];
            if (!tx) return send(res, 400, { status: false, message: 'Transaction reference not found' });
            if (tx.status === 'pending') return send(res, 200, { status: true, message: 'Verification successful', data: { ...txData(tx), status: 'abandoned' } });
            return send(res, 200, { status: true, message: 'Verification successful', data: txData(tx) });
        }
        if (url.pathname === '/refund' && req.method === 'POST') {
            const id = ++ps.refundSeq;
            const tx = ps.txs[body.transaction];
            const refund = { id, status: 'processed', amount: Number(body.amount ?? tx?.amount ?? 0), currency: body.currency || 'ZAR', transaction: { id: 1, reference: body.transaction, amount: tx?.amount ?? 0, currency: 'ZAR' } };
            ps.refunds[id] = refund;
            return send(res, 200, { status: true, message: 'Refund has been queued for processing', data: { ...refund, status: 'pending', integration: 1, deducted_amount: 0, merchant_note: body.merchant_note ?? '', customer_note: '', refunded_by: 'ops@example.test', expected_at: new Date().toISOString(), domain: 'test', fully_deducted: false } });
        }
        if ((m = url.pathname.match(/^\/refund\/(\d+)$/))) {
            const r = ps.refunds[m[1]];
            if (!r) return send(res, 404, { status: false, message: 'Refund not found' });
            return send(res, 200, { status: true, message: 'Refund retrieved', data: r });
        }
        if (url.pathname === '/transferrecipient') {
            return send(res, 200, { status: true, message: 'Transfer recipient created successfully', data: { active: true, currency: body.currency, name: body.name, type: body.type, recipient_code: 'RCP_e2e' + crypto.createHash('md5').update(String(body.account_number)).digest('hex').slice(0, 6), details: { account_number: body.account_number, bank_code: body.bank_code } } });
        }
        if (url.pathname === '/transfer' && req.method === 'POST') {
            ps.transfers[body.reference] = { reference: body.reference, amount: Number(body.amount), currency: body.currency, status: 'success' };
            return send(res, 200, { status: true, message: 'Transfer has been queued', data: { domain: 'test', amount: body.amount, currency: body.currency, reference: body.reference, source: 'balance', reason: body.reason, status: 'pending', transfer_code: 'TRF_' + body.reference.slice(-8), id: 1, integration: 1, recipient: 1, createdAt: new Date().toISOString(), updatedAt: new Date().toISOString() } });
        }
        if ((m = url.pathname.match(/^\/transfer\/verify\/(.+)$/))) {
            const t = ps.transfers[decodeURIComponent(m[1])];
            if (!t) return send(res, 404, { status: false, message: 'Transfer not found' });
            return send(res, 200, { status: true, message: 'Transfer retrieved', data: { ...t, transfer_code: 'TRF_' + t.reference.slice(-8) } });
        }
        return send(res, 404, { status: false, message: 'Not a stubbed endpoint' });
    });
    return listen(server);
}

/**
 * Paystack's hosted checkout: "view" returns the transaction; "pay"/"decline" marks it, delivers the signed
 * charge.success webhook on success, and returns the callback URL the app gave at initialize, as Paystack does.
 */
export async function checkoutAction(state, code, action) {
    const tx = Object.values(state.paystack.txs).find((t) => t.access_code === code);
    if (!tx) return null;
    if (action === 'view') return { tx };
    tx.status = action === 'pay' ? state.paystack.checkoutOutcome : 'failed';
    if (tx.status === 'success') await deliverWebhook(state, 'charge.success', txData(tx));
    const cb = new URL(tx.callback_url);
    cb.searchParams.set('trxref', tx.reference); cb.searchParams.set('reference', tx.reference);
    return { tx, location: cb.toString() };
}

// ------------------------------------------------------------------ AI (Anthropic Messages API shape), https

/** Fills a JSON schema with deterministic, clearly-marked stub values (strings carry the "[e2e stub]" prefix). */
function fill(schema, text, key = '') {
    if (!schema || typeof schema !== 'object') return null;
    if (schema.enum) return schema.enum[0];
    const type = Array.isArray(schema.type) ? schema.type.find((t) => t !== 'null') : schema.type;
    if (type === 'object' || schema.properties) {
        const out = {};
        for (const [k, s] of Object.entries(schema.properties || {})) out[k] = fill(s, text, k);
        return out;
    }
    if (type === 'array') return [fill(schema.items, text, key)];
    if (type === 'integer' || type === 'number') return 1;
    if (type === 'boolean') return false;
    return /translat|text|body|content|answer/i.test(key) ? `[e2e stub translation] ${text}` : `[e2e stub] ${key}`;
}

export function startAiStub(state, tls) {
    const server = https.createServer(tls, async (req, res) => {
        const url = new URL(req.url, 'https://x');
        const raw = await readBody(req);
        let body = {};
        try { body = raw ? JSON.parse(raw) : {}; } catch { /* ignore */ }
        const key = req.headers['x-api-key'] || (req.headers.authorization || '').replace(/^Bearer /, '');
        if (!String(key).startsWith('sk-ant-e2e')) {
            state.ai.rejected = (state.ai.rejected || 0) + 1;
            state.ai.lastRejectedHeaders = Object.keys(req.headers);
            return send(res, 401, { type: 'error', error: { type: 'authentication_error', message: 'invalid x-api-key' } });
        }
        if (url.pathname !== '/v1/messages' || req.method !== 'POST') return send(res, 404, { type: 'error', error: { type: 'not_found_error', message: 'stub' } });
        const content = body.messages?.[0]?.content;
        const userText = typeof content === 'string' ? content : (Array.isArray(content) ? content.map((b) => b.text || '').join('\n') : '');
        const quoted = (userText.match(/<([a-z_]+)>([\s\S]*?)<\/\1>/i)?.[2] || userText).trim().slice(0, 400);
        state.ai.calls.push({ model: body.model, system: String(body.system || '').slice(0, 200), user: userText.slice(0, 600) });
        const schema = body.output_config?.format?.schema;
        const out = state.ai.refuse ? '' : (schema ? JSON.stringify(fill(schema, quoted)) : `[e2e stub answer] ${quoted.slice(0, 120)}`);
        return send(res, 200, {
            id: 'msg_e2e_' + state.ai.calls.length, type: 'message', role: 'assistant', model: body.model,
            content: out ? [{ type: 'text', text: out }] : [], stop_reason: state.ai.refuse ? 'refusal' : 'end_turn', stop_sequence: null,
            usage: { input_tokens: 100, output_tokens: 50, cache_creation_input_tokens: 0, cache_read_input_tokens: 0 },
        });
    });
    return listen(server);
}

// ------------------------------------------------------------------ InfoDot (identity hub), http on "localhost"

function mintInfoDotToken(state, { email, name, verified = true }) {
    const token = 'idt_' + crypto.randomBytes(16).toString('hex');
    state.infodot.tokens[token] = { email, name, verified, used: false };
    return token;
}

export function startInfoDotStub(state) {
    const server = http.createServer(async (req, res) => {
        const url = new URL(req.url, 'http://x');
        const raw = await readBody(req);
        if (url.pathname === '/launch') {
            // InfoDot's launch page: a one-time token in a POST body (never in a URL), posted to the app.
            const token = mintInfoDotToken(state, { email: url.searchParams.get('email'), name: url.searchParams.get('name') });
            const redirect = url.searchParams.get('redirect') || '';
            return send(res, 200, `<!doctype html><html lang="en"><title>InfoDot (e2e stub)</title><h1>Open Dot.Tutor</h1>
<form method="post" action="${esc(state.appUrl)}/auth/ecosystem"><input type="hidden" name="token" value="${esc(token)}">${redirect ? `<input type="hidden" name="redirect" value="${esc(redirect)}">` : ''}<button id="open" type="submit">Continue to Dot.Tutor</button></form></html>`, 'text/html');
        }
        if (url.pathname === '/api/ecosystem/redeem' && req.method === 'POST') {
            let b = {};
            try { b = JSON.parse(raw || '{}'); } catch { /* ignore */ }
            const ok = (req.headers.authorization || '') === `Bearer ${state.infodot.secret}`;
            const t = state.infodot.tokens[b.token];
            state.infodot.redeems.push({ platform: b.platform, ok, known: !!t, reused: !!t?.used });
            if (!ok) return send(res, 401, { message: 'bad secret' });
            if (!t || t.used) return send(res, 404, { message: 'unknown or spent token' });
            t.used = true;
            const id = parseInt(crypto.createHash('md5').update(t.email).digest('hex').slice(0, 6), 16);
            return send(res, 200, { user: { id, name: t.name || t.email.split('@')[0], email: t.email, email_verified: t.verified }, teams: [{ id: id + 1, name: `${(t.name || 'E2E').split(' ')[0]}'s Team`, personal: true, role: 'owner' }], current_team_id: id + 1 });
        }
        return send(res, 404, { error: 'not_found' });
    });
    return listen(server, 'localhost');
}

// ------------------------------------------------------------------ SMTP capture

function decodeQp(s) {
    return s.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
}

export function startSmtpStub(state) {
    const server = net.createServer((sock) => {
        let buf = '', inData = false, rcpt = [], from = null;
        const say = (l) => sock.write(l + '\r\n');
        say('220 e2e-smtp ready');
        sock.on('data', (d) => {
            buf += d.toString('utf8');
            for (;;) {
                if (inData) {
                    const end = buf.indexOf('\r\n.\r\n');
                    if (end < 0) return;
                    const raw = buf.slice(0, end); buf = buf.slice(end + 5); inData = false;
                    const head = raw.split(/\r\n\r\n/)[0];
                    const header = (n) => (head.match(new RegExp(`^${n}: (.*(?:\\r\\n[ \\t].*)*)`, 'mi'))?.[1] || '').replace(/\r\n[ \t]/g, ' ');
                    let subject = header('Subject');
                    subject = subject.replace(/=\?utf-8\?Q\?(.*?)\?=/gi, (_, t) => decodeQp(t.replace(/_/g, ' '))).replace(/=\?utf-8\?B\?(.*?)\?=/gi, (_, t) => Buffer.from(t, 'base64').toString('utf8'));
                    state.mails.push({ from, to: rcpt, subject, body: decodeQp(raw).slice(0, 4000), at: Date.now() });
                    rcpt = []; say('250 OK queued');
                    continue;
                }
                const nl = buf.indexOf('\r\n');
                if (nl < 0) return;
                const line = buf.slice(0, nl); buf = buf.slice(nl + 2);
                const cmd = line.slice(0, 4).toUpperCase();
                if (cmd === 'EHLO' || cmd === 'HELO') { sock.write('250-e2e-smtp\r\n250 8BITMIME\r\n'); }
                else if (cmd === 'MAIL') { from = line.match(/<(.*)>/)?.[1]; say('250 OK'); }
                else if (cmd === 'RCPT') { rcpt.push(line.match(/<(.*)>/)?.[1]); say('250 OK'); }
                else if (cmd === 'DATA') { inData = true; say('354 go ahead'); }
                else if (cmd === 'QUIT') { say('221 bye'); sock.end(); }
                else say('250 OK');
            }
        });
        sock.on('error', () => {});
    });
    return listen(server);
}

function listen(server, host = '127.0.0.1') {
    return new Promise((resolve) => server.listen(0, host, () => resolve({ port: server.address().port, close: () => { server.closeAllConnections?.(); server.close(); } })));
}
