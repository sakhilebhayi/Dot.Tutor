// R-47 perf (DEVELOPMENT ONLY; docs/R47_PERF.md section 4): a gzip front for PHP's built-in server, standing in for
// the host's mod_deflate and the cache headers in public/.htaccess, so the throttled front-end runs can measure what a
// phone downloads from a configured host. Text types are gzipped (level 6, as mod_deflate's default); hashed build
// files get the immutable Cache-Control the .htaccess sets.
//   node scripts/perf/gzip-proxy.mjs 8732 http://127.0.0.1:8731
import http from 'node:http';
import zlib from 'node:zlib';

const [port = 8732, upstream = 'http://127.0.0.1:8731'] = process.argv.slice(2);
const target = new URL(upstream);
const TEXT = /^(text\/|application\/(javascript|json|manifest\+json)|image\/svg\+xml)/;

http.createServer((req, res) => {
    const up = http.request({ host: target.hostname, port: target.port, method: req.method, path: req.url, headers: req.headers }, (r) => {
        const headers = { ...r.headers };
        if (/^\/build\/assets\//.test(req.url)) headers['cache-control'] = 'public, max-age=31536000, immutable';
        else if (/^\/(fonts|icons|images)\//.test(req.url)) headers['cache-control'] = 'public, max-age=604800';
        const gzip = TEXT.test(String(r.headers['content-type'] || '')) && /\bgzip\b/.test(String(req.headers['accept-encoding'] || '')) && r.statusCode !== 304;
        if (!gzip) { res.writeHead(r.statusCode, headers); r.pipe(res); return; }
        delete headers['content-length'];
        headers['content-encoding'] = 'gzip';
        headers.vary = 'Accept-Encoding';
        res.writeHead(r.statusCode, headers);
        r.pipe(zlib.createGzip({ level: 6 })).pipe(res);
    });
    up.on('error', () => { res.writeHead(502); res.end(); });
    req.pipe(up);
}).listen(Number(port), '127.0.0.1');
