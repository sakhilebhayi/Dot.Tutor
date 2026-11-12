// The shared whiteboard. Every stroke, shape and text is an operation in an append-only log on the server; each
// browser replays the log for the page it shows. Coordinates are fractions of the page (0 to 1), so a phone and a wide
// screen see the same board. Colours are names that map to the design tokens, never values sent by people.

import { $ } from './util.js';

const ASPECT = 1.6; // 16:10 pages
const REF = 1000; // widths and text sizes are in thousandths of the page width
const COLOUR_TOKEN = { ink: '--td-ink', primary: '--td-primary', danger: '--td-danger', info: '--td-info', warning: '--td-warning', success: '--td-success' };

/** Round and clamp exactly as the server does, so a confirmed op can be matched to the pending one. */
export function normPoint(x, y) {
    const c = (v) => Math.round(Math.max(0, Math.min(1, v)) * 10000) / 10000;
    return [c(x), c(y)];
}

export function opKey(page, kind, data) {
    return `${page}|${kind}|${JSON.stringify(data)}`;
}

export class Board {
    constructor({ api, urls, announcer, onSaved }) {
        this.api = api;
        this.urls = urls;
        this.announcer = announcer;
        this.onSaved = onSaved || (() => {});
        this.pages = new Map(); // page -> list of {id, kind, data}
        this.pending = new Map(); // key -> {page, kind, data}
        this.seen = new Set();
        this.page = 1;
        this.pageCount = 1;
        this.tool = 'pen';
        this.colour = 'ink';
        this.width = 4;
        this.drawing = null;
        this.textAt = [0.5, 0.5];
        this.el = {
            sheet: $('cr-sheet'), canvas: $('cr-canvas'), alt: $('cr-board-text'), label: $('cr-page-label'),
            prev: $('cr-page-prev'), next: $('cr-page-next'), add: $('cr-page-add'), clear: $('cr-page-clear'), save: $('cr-page-save'),
            width: $('cr-width'), textForm: $('cr-text-form'), textInput: $('cr-text-input'),
        };
        this.ctx = this.el.canvas.getContext('2d');
        this.bind();
        this.resizeObserver = new ResizeObserver(() => this.resize());
        this.resizeObserver.observe(this.el.sheet);
        this.themeObserver = new MutationObserver(() => this.redraw());
        this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    bind() {
        const e = this.el;
        document.querySelectorAll('[data-tool]').forEach((b) => b.addEventListener('click', () => this.setTool(b.dataset.tool)));
        document.querySelectorAll('[data-colour]').forEach((b) => b.addEventListener('click', () => this.setColour(b.dataset.colour)));
        this.radioKeys('[data-tool]', (b) => this.setTool(b.dataset.tool, false));
        this.radioKeys('[data-colour]', (b) => this.setColour(b.dataset.colour));
        e.width.addEventListener('input', () => { this.width = Number(e.width.value) || 4; });
        e.prev.addEventListener('click', () => this.go(this.page - 1));
        e.next.addEventListener('click', () => this.go(this.page + 1));
        e.add.addEventListener('click', () => this.addPage());
        e.clear.addEventListener('click', () => {
            if (window.confirm(`Clear page ${this.page} for everyone? This cannot be undone.`)) this.commit('clear', {});
        });
        e.save.addEventListener('click', () => this.save());
        e.textForm.addEventListener('submit', (ev) => {
            ev.preventDefault();
            const text = e.textInput.value.trim();
            if (text) this.commit('text', { at: this.textAt, text, size: Math.round(this.width * 6 + 12), colour: this.colour });
            e.textInput.value = '';
            // Out of the way so the text shows; the next click on the page (or Enter on the Text tool) opens it again.
            e.textForm.hidden = true;
            this.el.sheet.focus?.();
        });
        const c = e.canvas;
        c.addEventListener('pointerdown', (ev) => this.down(ev));
        c.addEventListener('pointermove', (ev) => this.move(ev));
        c.addEventListener('pointerup', (ev) => this.up(ev));
        c.addEventListener('pointercancel', () => { this.drawing = null; this.redraw(); });
    }

    /** Arrow keys move between options of a radio group (WAI-ARIA radio pattern). */
    radioKeys(selector, choose) {
        const items = Array.from(document.querySelectorAll(selector));
        items.forEach((b, i) => {
            b.addEventListener('keydown', (ev) => {
                const step = ev.key === 'ArrowRight' || ev.key === 'ArrowDown' ? 1 : ev.key === 'ArrowLeft' || ev.key === 'ArrowUp' ? -1 : 0;
                if (!step) return;
                ev.preventDefault();
                const next = items[(i + step + items.length) % items.length];
                next.focus();
                choose(next);
            });
        });
        this.syncRadios(selector, items.find((b) => b.getAttribute('aria-checked') === 'true'));
    }

    syncRadios(selector, chosen) {
        document.querySelectorAll(selector).forEach((b) => {
            const on = b === chosen;
            b.setAttribute('aria-checked', on ? 'true' : 'false');
            b.tabIndex = on ? 0 : -1;
        });
    }

    setTool(tool, focusText = true) {
        this.tool = tool;
        this.syncRadios('[data-tool]', document.querySelector(`[data-tool="${tool}"]`));
        this.el.textForm.hidden = tool !== 'text';
        this.el.sheet.dataset.tool = tool;
        if (tool === 'text') {
            this.placeText(this.textAt);
            if (focusText) this.el.textInput.focus();
        }
    }

    setColour(colour) {
        this.colour = colour;
        this.syncRadios('[data-colour]', document.querySelector(`[data-colour="${colour}"]`));
    }

    // ── Server data ─────────────────────────────────────────────────────────

    setPageCount(n) {
        if (!n || n === this.pageCount) return;
        this.pageCount = n;
        this.renderNav();
    }

    /** Ops from a poll, in id order. Our own ops come back too: they confirm the pending copy instead of drawing twice. */
    ingest(ops) {
        let touched = false;
        for (const op of ops) {
            if (this.seen.has(op.id)) continue;
            this.seen.add(op.id);
            const key = opKey(op.page, op.kind, op.data);
            const list = this.pages.get(op.page) || [];
            const pendingIndex = list.findIndex((o) => o.pendingKey === key);
            if (pendingIndex >= 0) {
                list[pendingIndex] = { id: op.id, kind: op.kind, data: op.data };
            } else {
                list.push({ id: op.id, kind: op.kind, data: op.data });
            }
            this.pending.delete(key);
            this.pages.set(op.page, list);
            if (op.page === this.page) touched = true;
        }
        if (touched) this.redraw();
    }

    // ── Drawing ─────────────────────────────────────────────────────────────

    resize() {
        // The canvas fills the sheet by CSS (width 100%, aspect ratio 16:10); only the drawing buffer follows its size,
        // so measuring never changes what is measured.
        const w = Math.round(this.el.canvas.clientWidth);
        if (!w || (this.size && this.size.w === w)) return;
        const hgt = Math.round(w / ASPECT);
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        const c = this.el.canvas;
        c.width = Math.round(w * dpr);
        c.height = Math.round(hgt * dpr);
        this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        this.size = { w, h: hgt };
        this.redraw();
        if (this.tool === 'text') this.placeText(this.textAt);
    }

    colourOf(name) {
        const v = getComputedStyle(document.documentElement).getPropertyValue(COLOUR_TOKEN[name] || '--td-ink').trim();
        return v || '#11211a';
    }

    redraw(ctx = this.ctx, size = this.size, colourOf = (n) => this.colourOf(n)) {
        if (!size) return;
        ctx.clearRect(0, 0, size.w, size.h);
        const ops = this.pages.get(this.page) || [];
        let start = 0;
        ops.forEach((op, i) => { if (op.kind === 'clear') start = i + 1; });
        for (const op of ops.slice(start)) this.drawOp(ctx, size, op.kind, op.data, colourOf);
        if (this.drawing && ctx === this.ctx) this.drawOp(ctx, size, this.drawing.kind, this.drawing.data, colourOf);
        if (ctx === this.ctx) this.describe(ops.slice(start));
    }

    drawOp(ctx, { w, h: hgt }, kind, d, colourOf) {
        const s = w / REF;
        ctx.save();
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.lineWidth = Math.max(1, (d.width || 3) * s * 2);
        ctx.strokeStyle = colourOf(d.colour || 'ink');
        ctx.fillStyle = ctx.strokeStyle;
        if (kind === 'erase') {
            ctx.globalCompositeOperation = 'destination-out';
            ctx.lineWidth = Math.max(6, (d.width || 3) * s * 6);
            ctx.strokeStyle = '#000';
        }
        const P = ([x, y]) => [x * w, y * hgt];
        if (kind === 'pen' || kind === 'erase') {
            const pts = d.points || [];
            ctx.beginPath();
            if (pts.length === 1) {
                const [x, y] = P(pts[0]);
                ctx.arc(x, y, ctx.lineWidth / 2, 0, Math.PI * 2);
                ctx.fill();
            } else {
                pts.forEach((p, i) => { const [x, y] = P(p); if (i) ctx.lineTo(x, y); else ctx.moveTo(x, y); });
                ctx.stroke();
            }
        } else if (kind === 'line' || kind === 'rect' || kind === 'ellipse') {
            const [x1, y1] = P(d.from);
            const [x2, y2] = P(d.to);
            ctx.beginPath();
            if (kind === 'line') { ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); }
            else if (kind === 'rect') ctx.rect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
            else ctx.ellipse((x1 + x2) / 2, (y1 + y2) / 2, Math.abs(x2 - x1) / 2, Math.abs(y2 - y1) / 2, 0, 0, Math.PI * 2);
            ctx.stroke();
        } else if (kind === 'text') {
            const [x, y] = P(d.at);
            ctx.font = `600 ${Math.round((d.size || 24) * s)}px "Atkinson Hyperlegible Next", system-ui, sans-serif`;
            ctx.textBaseline = 'top';
            ctx.fillText(d.text, x, y);
        }
        ctx.restore();
    }

    /** A text alternative for the canvas: the words on the page, and how many drawings. */
    describe(ops) {
        const texts = ops.filter((o) => o.kind === 'text').map((o) => o.data.text);
        const marks = ops.filter((o) => o.kind !== 'text' && o.kind !== 'erase').length;
        const parts = [];
        if (texts.length) parts.push(`Text on this page: ${texts.join('; ')}.`);
        if (marks) parts.push(`${marks} drawing${marks === 1 ? '' : 's'}.`);
        this.el.alt.textContent = parts.length ? parts.join(' ') : 'Nothing on this page yet.';
    }

    pointFrom(ev) {
        const r = this.el.canvas.getBoundingClientRect();
        return normPoint((ev.clientX - r.left) / r.width, (ev.clientY - r.top) / r.height);
    }

    down(ev) {
        if (ev.button !== undefined && ev.button !== 0) return;
        const p = this.pointFrom(ev);
        if (this.tool === 'text') {
            this.placeText(p);
            this.el.textForm.hidden = false;
            this.el.textInput.focus();
            return;
        }
        this.el.canvas.setPointerCapture(ev.pointerId);
        ev.preventDefault();
        if (this.tool === 'pen' || this.tool === 'erase') {
            this.drawing = { kind: this.tool, data: { points: [p], width: this.width, ...(this.tool === 'pen' ? { colour: this.colour } : {}) } };
        } else {
            this.drawing = { kind: this.tool, data: { from: p, to: p, width: this.width, colour: this.colour } };
        }
        this.redraw();
    }

    move(ev) {
        if (!this.drawing) return;
        const p = this.pointFrom(ev);
        const d = this.drawing.data;
        if (d.points) {
            const last = d.points[d.points.length - 1];
            if (Math.hypot(p[0] - last[0], p[1] - last[1]) < 0.002 || d.points.length >= 1900) return;
            d.points.push(p);
        } else {
            d.to = p;
        }
        this.redraw();
    }

    up() {
        if (!this.drawing) return;
        const { kind, data } = this.drawing;
        this.drawing = null;
        this.commit(kind, data);
    }

    placeText(p) {
        this.textAt = p;
        const f = this.el.textForm;
        f.style.left = `${Math.min(p[0], 0.7) * 100}%`;
        f.style.top = `${Math.min(p[1], 0.88) * 100}%`;
    }

    // ── Sending ─────────────────────────────────────────────────────────────

    async commit(kind, data) {
        const clean = this.normalise(kind, data);
        const key = opKey(this.page, kind, clean);
        const list = this.pages.get(this.page) || [];
        list.push({ id: null, kind, data: clean, pendingKey: key });
        this.pages.set(this.page, list);
        this.pending.set(key, true);
        this.redraw();
        try {
            const reply = await this.api.post(this.urls.board, { page: this.page, kind, data: clean });
            const at = list.findIndex((o) => o.pendingKey === key && o.id === null);
            if (at >= 0) {
                if (this.seen.has(reply.id)) list.splice(at, 1); // the poll brought the server's copy first (it may differ: hidden contact details)
                else { list[at] = { id: reply.id, kind, data: clean }; this.seen.add(reply.id); }
                this.pending.delete(key);
                this.redraw();
            }
        } catch (e) {
            const at = list.findIndex((o) => o.pendingKey === key && o.id === null);
            if (at >= 0) list.splice(at, 1);
            this.pending.delete(key);
            this.redraw();
            this.announcer.say(e.message || 'That did not reach the whiteboard');
        }
    }

    normalise(kind, d) {
        const width = Math.max(1, Math.min(40, Math.round(d.width || 3)));
        if (kind === 'pen') return { points: d.points.map(([x, y]) => normPoint(x, y)), width, colour: d.colour };
        if (kind === 'erase') return { points: d.points.map(([x, y]) => normPoint(x, y)), width };
        if (kind === 'text') return { at: normPoint(...d.at), text: d.text, size: Math.max(12, Math.min(72, d.size)), colour: d.colour };
        if (kind === 'clear') return [];
        return { from: normPoint(...d.from), to: normPoint(...d.to), width, colour: d.colour };
    }

    go(page) {
        if (page < 1 || page > this.pageCount) return;
        this.page = page;
        this.renderNav();
        this.redraw();
    }

    renderNav() {
        this.el.label.textContent = `Page ${this.page} of ${this.pageCount}`;
        this.el.prev.disabled = this.page <= 1;
        this.el.next.disabled = this.page >= this.pageCount;
    }

    async addPage() {
        try {
            const reply = await this.api.post(this.urls.boardPage, {});
            this.pageCount = reply.pages;
            this.go(reply.pages);
            this.announcer.say(`Page ${reply.pages} added`);
        } catch (e) {
            this.announcer.say(e.message || 'Could not add a page');
        }
    }

    /** Render the page on the paper colour and keep it with the lesson files. */
    async save() {
        if (!this.size) return;
        const scale = 2;
        const off = document.createElement('canvas');
        off.width = this.size.w * scale;
        off.height = this.size.h * scale;
        const ctx = off.getContext('2d');
        ctx.setTransform(scale, 0, 0, scale, 0, 0);
        const layer = document.createElement('canvas');
        layer.width = off.width;
        layer.height = off.height;
        const lctx = layer.getContext('2d');
        lctx.setTransform(scale, 0, 0, scale, 0, 0);
        // A printed page is always light, whatever theme the viewer uses.
        const light = { ink: '#11211a', primary: '#1f6b4f', danger: '#b3261e', info: '#1d5fa8', warning: '#8a5a00', success: '#2e7d32' };
        this.redraw(lctx, this.size, (n) => light[n] || light.ink);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, this.size.w, this.size.h);
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.drawImage(layer, 0, 0);
        const blob = await new Promise((resolve) => off.toBlob(resolve, 'image/png'));
        if (!blob) return;
        const form = new FormData();
        form.append('page', String(this.page));
        form.append('image', blob, `whiteboard-${this.page}.png`);
        this.el.save.disabled = true;
        try {
            const reply = await this.api.upload(this.urls.boardSnapshot, form);
            this.announcer.say(`Saved to the lesson files as ${reply.title}`);
            this.onSaved(reply);
        } catch (e) {
            this.announcer.say(e.message || 'The page could not be saved');
        } finally {
            this.el.save.disabled = false;
        }
    }

    destroy() {
        this.resizeObserver.disconnect();
        this.themeObserver.disconnect();
    }
}

