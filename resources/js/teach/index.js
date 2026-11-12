// Tutor workspace behaviour (tutor UI agent). Each module enhances markup that already works without script:
// forms still submit normally, and the server works out every figure again.
import { toMinor, formatMinor } from './split.js';

/** Live "what you keep" preview: asks the server (same commission rule as an order) as the price changes. */
function initPricePreview(root) {
    root.querySelectorAll('form[data-teach-price-form]').forEach((form) => {
        const input = form.querySelector('[data-teach-price]');
        const split = form.querySelector('[data-teach-split]');
        const status = form.querySelector('[data-split="status"]');
        const url = form.dataset.previewUrl;
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!input || !split || !url || !token) {
            return;
        }
        let timer = null;
        let seq = 0;
        const set = (key, text) => {
            const el = split.querySelector(`[data-split="${key}"]`);
            if (el) {
                el.textContent = text;
            }
        };
        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                const minor = toMinor(input.value);
                if (minor === null) {
                    return;
                }
                const mine = ++seq;
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                        body: JSON.stringify({ price_minor: minor, category: form.dataset.category || 'lesson' }),
                        credentials: 'same-origin',
                    });
                    if (!response.ok || mine !== seq) {
                        return;
                    }
                    const data = await response.json();
                    set('price', formatMinor(data.price_minor, data.currency));
                    set('percent', String(data.commission_percent));
                    set('commission', '−' + formatMinor(data.commission_minor, data.currency));
                    set('net', formatMinor(data.net_minor, data.currency));
                    const charges = split.querySelector('[data-split="charges"]');
                    if (charges) {
                        charges.replaceChildren(...data.charges.map((c) => {
                            const row = document.createElement('div');
                            row.className = 'flex justify-between gap-3';
                            const dt = document.createElement('dt');
                            dt.textContent = c.label;
                            const dd = document.createElement('dd');
                            dd.className = 'm-0 tabular-nums';
                            dd.textContent = '−' + formatMinor(c.minor, data.currency);
                            row.append(dt, dd);
                            return row;
                        }));
                    }
                } catch {
                    if (status) {
                        status.textContent = 'The preview could not be updated. Saving still works out the figures on the server.';
                    }
                }
            }, 250);
        });
    });
}

/** Uploads with a progress bar: the form is sent with XHR so its upload progress can be shown. */
function initUploads(root) {
    root.querySelectorAll('form[data-teach-upload]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const files = [...form.querySelectorAll('input[type="file"]')].flatMap((i) => [...(i.files ?? [])]);
            if (files.length === 0 || typeof XMLHttpRequest === 'undefined') {
                return;
            }
            event.preventDefault();
            const submit = form.querySelector('[type="submit"]');
            let meter = form.querySelector('[data-teach-progress]');
            if (!meter) {
                meter = document.createElement('div');
                meter.dataset.teachProgress = '';
                meter.className = 'grid gap-1';
                meter.innerHTML = '<span class="text-sm" aria-live="polite"></span><progress max="100" value="0" class="w-full"></progress>';
                form.append(meter);
            }
            const label = meter.querySelector('span');
            const bar = meter.querySelector('progress');
            const xhr = new XMLHttpRequest();
            xhr.open(form.method.toUpperCase(), form.action);
            xhr.setRequestHeader('Accept', 'text/html');
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    bar.value = pct;
                    label.textContent = pct < 100 ? `Uploading: ${pct}%` : 'Uploaded. Checking the file on the server…';
                }
            });
            xhr.addEventListener('load', () => {
                // The XHR already followed the server's redirect, and that request read the one-time flash (the
                // success message or the validation errors). Show the page it received instead of loading it again.
                history.replaceState(null, '', xhr.responseURL || window.location.href);
                document.open();
                document.write(xhr.responseText);
                document.close();
            });
            xhr.addEventListener('error', () => {
                label.textContent = 'The upload stopped. Check your connection and try again.';
                if (submit) {
                    submit.disabled = false;
                }
            });
            if (submit) {
                submit.disabled = true;
            }
            label.textContent = 'Uploading: 0%';
            xhr.send(new FormData(form));
        });
    });
}

/** Copy buttons next to read-only link fields; shown only when the clipboard can be used. */
function initCopy(root) {
    if (!navigator.clipboard) {
        return;
    }
    root.querySelectorAll('[data-teach-copy]').forEach((button) => {
        const field = document.getElementById(button.dataset.teachCopy);
        if (!field) {
            return;
        }
        button.hidden = false;
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(field.value);
                window.tdToast?.('Link copied.', 'success');
            } catch {
                field.select();
            }
        });
    });
}

function boot(root = document) {
    initPricePreview(root);
    initUploads(root);
    initCopy(root);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => boot());
} else {
    boot();
}
