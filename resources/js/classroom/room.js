// The Dot.Tutor live classroom. Browsers connect to each other over WebRTC (a mesh of up to the room's cap); this page
// talks to the server only to learn who is in the room, to pass connection set-up messages, to say "I am still here"
// (which is also what attendance counts), and for the lesson's chat, whiteboard, notes and files.
// The call logic follows Dot.Engage's proven meeting room (perfect negotiation, ICE restart, rebuild, give up).

import { $, h, fmtClock, fmtTime, icon, initials, isTyping, randomId, safeParse, setSprite, sleep, swapIcon, worstQuality, QUALITY_WORD } from './util.js';
import { ApiError, createApi } from './api.js';
import { Announcer } from './announce.js';
import { LocalMedia } from './media.js';
import { closeShared, resumeShared } from './audio.js';
import { Lobby } from './lobby.js';
import { Peer } from './peer.js';
import { Board } from './board.js';

const POLL_BUSY_MS = 1000;
const POLL_CALM_MS = 2000;
const POLL_HIDDEN_MS = 5000;
const BUSY_AFTER_CHANGE_MS = 20000;
const STATS_MS = 2000;
const NOTES_SAVE_MS = 900;
const ROLE_WORD = { tutor: 'Tutor', learner: 'Learner', guardian: 'Guardian, watching' };
const REPORT_CATEGORIES = [
    ['harassment', 'Harassment or bullying'],
    ['inappropriate_contact_minor', 'Inappropriate contact with a learner under 18'],
    ['cheating_request', 'Asking someone to cheat'],
    ['spam', 'Spam or scam'],
    ['other', 'Something else'],
];

class Classroom {
    constructor(root) {
        this.root = root;
        this.cfg = safeParse(root.dataset.config) || {};
        setSprite(root.dataset.sprite);
        this.urls = this.cfg.urls || {};
        this.clientId = randomId(10);
        this.sid = this.clientId;
        this.api = createApi({ clientId: this.clientId });
        this.announcer = new Announcer();
        this.media = new LocalMedia();
        this.ac = new AbortController();
        this.baseTitle = document.title;

        this.phase = 'lobby';
        this.myId = null;
        this.role = this.cfg.role;
        this.since = 0;
        this.chatSince = 0;
        this.boardSince = 0;
        this.notesVersion = -1;
        this.filesSince = -1;
        this.peers = new Map();
        this.roster = [];
        this.rosterById = new Map();
        this.rosterReady = false;
        this.lastRosterChange = 0;
        this.iceServers = [];
        this.relay = false;
        this.externalUrl = this.cfg.externalUrl || null;
        this.pollTimer = null;
        this.polling = false;
        this.pollFailures = 0;
        this.net = 'ok';
        this.timers = {};
        this.sendQueue = [];
        this.pumping = false;
        this.stopped = false;
        this.tiles = new Map();
        this.notesDirty = false;
        this.notesSaving = false;
        this.banners = new Map();

        this.el = {
            status: $('cr-status'), clock: $('cr-clock'), net: $('cr-net'), lobby: $('cr-lobby'), live: $('cr-live'),
            notice: $('cr-notice'), banner: $('cr-banner'), grid: $('cr-grid'), strip: $('cr-strip'),
            join: $('cr-join'), lobbyMsg: $('cr-lobby-msg'),
            mic: $('cr-mic'), cam: $('cr-cam'), share: $('cr-share'), devices: $('cr-devices'), devicesPanel: $('cr-devices-panel'),
            leave: $('cr-leave'), end: $('cr-end'),
            chatLog: $('cr-chat-log'), chatForm: $('cr-chat-form'), chatInput: $('cr-chat-input'),
            notes: $('cr-notes'), notesState: $('cr-notes-state'), notesConflict: $('cr-notes-conflict'), notesMine: $('cr-notes-mine'),
            files: $('cr-files'), upload: $('cr-upload'), file: $('cr-file'), fileTitle: $('cr-file-title'), uploadMsg: $('cr-upload-msg'),
            people: $('cr-people'),
        };

        const room = this;
        this.peerCtx = {
            get myId() { return room.myId; },
            sid: this.sid,
            media: this.media,
            iceServers: () => this.iceServers,
            send: (type, to, payload) => this.queueSignal(type, to, payload),
            changed: (peer, what) => this.onPeerChanged(peer, what),
            log: (...args) => this.log(...args),
        };

        this.lobby = new Lobby({ media: this.media, announcer: this.announcer });
        this.bind();
        this.lobby.start().then((ok) => {
            if (!ok) { this.el.join.disabled = true; return; }
            this.armJoin();
        });
    }

    log(...args) {
        try { if (localStorage.getItem('classroom-debug')) console.debug('[classroom]', ...args); } catch (e) { /* storage blocked */ }
    }

    bind() {
        const signal = this.ac.signal;
        const on = (target, name, fn) => target && target.addEventListener(name, fn, { signal });
        const el = this.el;
        on(el.join, 'click', () => this.join());
        on(el.mic, 'click', () => this.toggleMic());
        on(el.cam, 'click', () => this.toggleCam());
        on(el.share, 'click', () => this.toggleShare());
        on(el.leave, 'click', () => this.leave());
        on(el.end, 'click', () => this.endForEveryone());
        on(el.devices, 'toggle', () => { if (el.devices.open) this.renderDevices(); });
        on(el.chatForm, 'submit', (e) => { e.preventDefault(); this.sendChat(); });
        on(el.chatInput, 'keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.sendChat(); } });
        on(el.notes, 'input', () => this.notesChanged());
        on(el.upload, 'submit', (e) => { e.preventDefault(); this.uploadFile(); });
        on(document, 'keydown', (e) => this.onKey(e));
        on(document, 'visibilitychange', () => { if (!document.hidden) this.pollNow(); });
        on(window, 'online', () => { this.pollFailures = 0; this.pumpSignals(); this.peers.forEach((p) => p.kick()); this.pollNow(); });
        on(window, 'offline', () => { if (this.phase === 'live') this.setNet('reconnecting'); });
        on(window, 'pagehide', () => { if (this.phase === 'live' && !this.stopped) this.api.post(this.urls.leave, {}, { keepalive: true }).catch(() => {}); });

        this.media.on('track', (kind, track) => { this.peers.forEach((p) => p.setLocalTrack(kind, track)); if (this.phase === 'live') this.afterLocalChange(); });
        this.media.on('state', () => this.syncControls());
        this.media.on('screen-ended', () => this.stopShare());
        this.media.on('lost', (kind) => {
            this.banner('device', kind === 'audio' ? 'Your microphone stopped working. Pick another under Devices.' : 'Your camera stopped working. Pick another under Devices.', 'warn', 10000);
            this.afterLocalChange();
        });
    }

    /** The join button opens when the room does (the server checks again on Join). */
    armJoin() {
        const btn = this.el.join;
        if (!btn) return;
        const opens = btn.dataset.opens ? Date.parse(btn.dataset.opens) : 0;
        if (btn.disabled && opens) {
            const wait = opens - Date.now();
            if (wait > 0 && wait < 3 * 3600 * 1000) {
                this.timers.open = setTimeout(() => {
                    btn.disabled = false;
                    btn.removeAttribute('aria-disabled');
                    this.el.lobbyMsg.textContent = 'The classroom is open. You can join now.';
                    this.announcer.say('The classroom is open');
                }, wait + 500);
            }
        }
        if (!btn.disabled) btn.focus({ preventScroll: true });
    }

    setStatus(text, state) {
        this.el.status.textContent = text;
        this.el.status.dataset.state = state;
    }

    // ── Joining ─────────────────────────────────────────────────────────────

    async join() {
        if (this.phase !== 'lobby') return;
        resumeShared();
        this.phase = 'joining';
        this.el.join.disabled = true;
        this.el.join.setAttribute('aria-busy', 'true');
        this.el.lobbyMsg.textContent = 'Joining';
        try {
            const reply = await this.api.post(this.urls.join, { client_id: this.clientId });
            this.myId = reply.attendee_id;
            this.role = reply.role || this.role;
            this.iceServers = reply.ice || [];
            this.relay = !!reply.relay;
            this.enterCall();
        } catch (e) {
            this.phase = 'lobby';
            this.el.join.disabled = false;
            this.el.join.removeAttribute('aria-busy');
            this.el.lobbyMsg.textContent = e instanceof ApiError && e.status === 429 ? 'Too many tries. Wait a moment and press Join again.'
                : e instanceof ApiError && e.network ? 'Could not reach Dot.Tutor. Check your connection and press Join again.'
                    : `${e.message || 'Could not join.'}`;
            this.announcer.say(this.el.lobbyMsg.textContent);
        }
    }

    enterCall() {
        this.lobby.stop();
        this.phase = 'live';
        this.el.lobby.hidden = true;
        this.el.live.hidden = false;
        document.querySelectorAll('#cr-gate, .cr-approvals').forEach((n) => { n.hidden = true; });
        this.root.dataset.phase = 'live';
        this.joinedAt = Date.now();
        this.lastRosterChange = Date.now();
        this.setStatus('Live', 'live');
        this.el.clock.hidden = false;
        this.el.net.hidden = false;
        this.board = new Board({ api: this.api, urls: this.urls, announcer: this.announcer, onSaved: () => { this.filesSince = -1; this.pollNow(); } });
        this.syncControls();
        this.renderTiles();
        this.timers.clock = setInterval(() => { this.el.clock.textContent = fmtClock((Date.now() - this.joinedAt) / 1000); }, 1000);
        this.timers.stats = setInterval(() => this.sampleAll(), STATS_MS);
        this.schedulePoll(0);
        if (!this.media.canShare) {
            this.el.share.disabled = true;
            this.el.share.title = 'Screen sharing is not available on this device';
        }
        if (this.role === 'guardian' && this.media.hasAudio) this.media.setMic(false);
        this.el.mic.focus({ preventScroll: true });
        this.announcer.say('You joined the lesson');
    }

    // ── Polling ─────────────────────────────────────────────────────────────

    schedulePoll(delay) {
        clearTimeout(this.pollTimer);
        if (this.stopped) return;
        this.pollTimer = setTimeout(() => this.pollTick(), delay ?? this.nextDelay());
    }

    nextDelay() {
        if (this.pollFailures) return Math.min(15000, 1000 * 2 ** Math.min(this.pollFailures - 1, 4)) + Math.random() * 400;
        const negotiating = [...this.peers.values()].some((p) => p.status !== 'connected' && p.status !== 'dead');
        const busy = negotiating || this.sendQueue.length > 0 || Date.now() - this.lastRosterChange < BUSY_AFTER_CHANGE_MS;
        if (document.hidden && !busy) return POLL_HIDDEN_MS;
        return busy ? POLL_BUSY_MS : POLL_CALM_MS;
    }

    pollNow() {
        if (this.phase !== 'live' || this.stopped) return;
        if (this.polling) { this.pollAgain = true; return; }
        this.schedulePoll(0);
    }

    async pollTick() {
        if (this.polling || this.stopped) return;
        this.polling = true;
        const started = performance.now();
        try {
            const body = {
                since: this.since, chat_since: this.chatSince, board_since: this.boardSince, notes_version: this.notesVersion,
                files_since: this.filesSince, mic: this.micOn, cam: this.camOn, sharing: this.sharing,
            };
            if (this.ownQuality) body.quality = this.ownQuality;
            const data = await this.api.post(this.urls.poll, body);
            if (!data.me || !Array.isArray(data.roster)) throw new ApiError(502, 'The server sent something unexpected.');
            this.latency = performance.now() - started;
            this.pollFailures = 0;
            if (this.net === 'reconnecting') this.setNet('ok');
            if (!this.stopped) this.onSnapshot(data);
        } catch (e) {
            this.onPollError(e);
        } finally {
            this.polling = false;
            if (!this.stopped) {
                if (this.pollAgain) { this.pollAgain = false; this.schedulePoll(50); } else this.schedulePoll();
            }
        }
    }

    onPollError(e) {
        this.log('poll failed', e);
        if (e instanceof ApiError) {
            if (e.displaced) return this.finish('displaced');
            if (e.code === 'removed') return this.finish('removed');
            if (e.code === 'ended' || e.code === 'closed' || e.code === 'lesson_over') return this.finish('ended');
            if (e.status === 419 || e.status === 401) return this.finish('expired');
            if (e.status === 403) return this.finish('refused', e.message);
            if (e.notJoined) return this.rejoin();
            if (e.status === 429) { this.pollFailures = Math.max(this.pollFailures, 3); return; }
        }
        this.pollFailures += 1;
        if (this.pollFailures >= 2 || !navigator.onLine) this.setNet('reconnecting');
    }

    async rejoin() {
        if (this.rejoining) return;
        this.rejoining = true;
        try {
            const reply = await this.api.post(this.urls.join, { client_id: this.clientId });
            this.myId = reply.attendee_id;
        } catch (e) {
            if (e instanceof ApiError && e.status >= 400 && e.status < 500 && e.status !== 429) this.finish('refused', e.message);
        } finally {
            this.rejoining = false;
        }
    }

    setNet(state) {
        if (this.net === state) return;
        this.net = state;
        if (state === 'reconnecting') {
            this.setStatus('Reconnecting', 'warn');
            this.banner('net', 'Reconnecting. Your connection to Dot.Tutor dropped; the lesson carries on when it is back.', 'warn');
        } else {
            this.setStatus('Live', 'live');
            this.clearBanner('net');
            this.announcer.say('Connection restored');
            this.pumpSignals();
            this.peers.forEach((p) => p.kick());
        }
        this.updateNet();
    }

    onSnapshot(data) {
        this.since = Math.max(this.since, data.last_id | 0);
        if (data.classroom && data.classroom.status === 'ended') return this.finish('ended');
        const me = data.me || {};
        this.myId = me.id ?? this.myId;
        if (me.state === 'removed') return this.finish('removed');
        if (me.state !== 'joined') return this.rejoin();

        this.applyRoster(data.roster);
        this.dispatchSignals(data.signals || []);
        if (data.chat?.length) this.renderChat(data.chat);
        if (data.board) {
            if (data.board.length) this.boardSince = data.board[data.board.length - 1].id;
            this.board.ingest(data.board);
        }
        if (data.classroom) this.board.setPageCount(data.classroom.board_pages);
        if (data.notes) this.receiveNotes(data.notes);
        if (data.files) { this.filesSince = data.files_latest | 0; this.renderFiles(data.files); }
        if ('external_url' in data) this.externalUrl = data.external_url;
        this.renderTiles();
        this.renderPeople();
        this.syncControls();
        this.updateNet();
    }

    // ── Roster and peers (from Dot.Engage) ──────────────────────────────────

    applyRoster(roster) {
        const now = new Map(roster.map((r) => [r.id, r]));
        const prev = this.rosterById;
        let changed = false;
        for (const r of roster) {
            if (r.me) continue;
            const old = prev.get(r.id);
            if (!old || old.state !== r.state || old.sharing !== r.sharing) changed = true;
            if (!this.rosterReady) continue;
            if (!old) this.announcer.say(`${r.name} joined`);
            else if (old.state === 'joined' && r.state === 'lost') this.announcer.say(`${r.name} lost connection`);
            if (old && !old.sharing && r.sharing) this.announcer.say(`${r.name} started sharing their screen`);
        }
        for (const old of prev.values()) {
            if (old.me || now.has(old.id)) continue;
            changed = true;
            if (this.rosterReady) this.announcer.say(`${old.name} left`);
        }
        if (changed) this.lastRosterChange = Date.now();
        this.roster = roster;
        this.rosterById = now;
        this.rosterReady = true;

        for (const r of roster) {
            if (r.me) continue;
            const peer = this.peers.get(r.id);
            if (!peer) this.createPeer(r);
            else peer.remote = r;
        }
        for (const id of [...this.peers.keys()]) {
            if (!now.has(id)) this.removePeer(id);
        }
        const count = this.peers.size;
        if (count !== this.lastBandwidthCount) {
            this.lastBandwidthCount = count;
            this.peers.forEach((p) => p.applyBandwidth(count));
        }
    }

    createPeer(remote, { fromOffer = false } = {}) {
        const peer = new Peer(this.peerCtx, remote, { fromOffer });
        this.peers.set(remote.id, peer);
        return peer;
    }

    removePeer(id) {
        const peer = this.peers.get(id);
        if (!peer) return;
        peer.close();
        this.peers.delete(id);
        this.clearBanner(`dead-${id}`);
    }

    onPeerChanged(peer, what) {
        if (this.stopped) return;
        if (what === 'connected' || what === 'recovered') {
            peer.applyBandwidth(this.peers.size);
            this.clearBanner(`dead-${peer.id}`);
            this.announcer.say(what === 'recovered' ? `Reconnected to ${peer.name}` : `Connected to ${peer.name}`);
        }
        if (what === 'dead') this.cannotConnect(peer);
        if (this.phase === 'live') { this.renderTiles(); this.renderPeople(); }
    }

    /** The honest fallback: no relay exists, so say so and offer the tutor's own meeting link. */
    cannotConnect(peer) {
        const actions = [{ label: 'Try again', run: () => { peer.retry(); this.clearBanner(`dead-${peer.id}`); } }];
        if (this.externalUrl) actions.unshift({ label: this.role === 'tutor' ? 'Open your meeting link' : "Open the tutor's meeting link", href: this.externalUrl });
        const tail = this.relay ? 'Even the relay server could not reach them.'
            : 'No relay server is set up yet, so some networks (schools, offices, some mobile data) block a direct connection.';
        const link = this.externalUrl ? '' : (this.role === 'tutor' ? ' Add your own meeting link below the classroom to offer a way round it.' : ' Ask the tutor to add a meeting link.');
        this.banner(`dead-${peer.id}`, `Your browser could not connect to ${peer.name}. ${tail}${link}`, 'danger', 0, actions);
    }

    dispatchSignals(signals) {
        for (const s of signals) {
            const payload = safeParse(s.payload);
            if (!payload || typeof payload !== 'object') continue;
            const sender = this.rosterById.get(s.from);
            if (s.type === 'command') {
                if (sender && sender.role === 'tutor') this.onTutorCommand(payload.command);
                continue;
            }
            if (!sender) continue;
            let peer = this.peers.get(s.from);
            if (!peer) {
                if (s.type !== 'offer') continue;
                peer = this.createPeer(sender, { fromOffer: true });
            }
            peer.receive(s.type, payload);
        }
    }

    queueSignal(type, to, payload) {
        if (this.stopped) return;
        this.sendQueue.push({ type, to, payload, at: Date.now(), tries: 0 });
        this.pumpSignals();
    }

    async pumpSignals() {
        if (this.pumping) return;
        this.pumping = true;
        try {
            while (this.sendQueue.length && !this.stopped) {
                const item = this.sendQueue[0];
                if (item.type === 'candidate' && Date.now() - item.at > 30000) { this.sendQueue.shift(); continue; }
                try {
                    await this.api.post(this.urls.signal, { to: item.to, type: item.type, payload: item.payload });
                    this.sendQueue.shift();
                } catch (e) {
                    if (e instanceof ApiError && e.displaced) { this.finish('displaced'); break; }
                    if (e instanceof ApiError && [403, 404, 409, 422].includes(e.status)) { this.sendQueue.shift(); continue; }
                    item.tries += 1;
                    if (item.tries > 8) { this.sendQueue.shift(); continue; }
                    await sleep(e instanceof ApiError && e.status === 429 ? 3000 : Math.min(6000, 400 * 2 ** item.tries));
                }
            }
        } finally {
            this.pumping = false;
        }
    }

    onTutorCommand(command) {
        if (command === 'mute') {
            if (this.media.hasAudio && this.media.micOn) { this.media.setMic(false); this.afterLocalChange(); }
            this.banner('muted', 'The tutor muted your microphone. Turn it on when you want to speak.', 'info', 9000);
            this.announcer.say('The tutor muted you');
        } else if (command === 'removed') {
            this.finish('removed');
        }
    }

    // ── My media ────────────────────────────────────────────────────────────

    get micOn() { return this.media.hasAudio && this.media.micOn; }
    get camOn() { return this.media.hasVideo && this.media.camOn; }
    get sharing() { return !!this.media.screenTrack; }

    toggleMic() {
        if (!this.media.hasAudio) { this.banner('device', 'No microphone is available, so you are listening only.', 'warn', 6000); return; }
        this.media.setMic(!this.media.micOn);
        this.announcer.say(this.media.micOn ? 'Microphone on' : 'Microphone off');
        this.afterLocalChange();
    }

    async toggleCam() {
        const wasOn = this.camOn;
        this.el.cam.disabled = true;
        try {
            await this.media.setCam(!wasOn);
            this.announcer.say(this.camOn ? 'Camera on' : 'Camera off');
        } catch (e) {
            this.banner('device', 'The camera could not be turned on. It may be blocked or in use by another app.', 'warn', 8000);
        }
        this.afterLocalChange();
    }

    async toggleShare() {
        if (this.sharing) return this.stopShare();
        const other = this.roster.find((r) => !r.me && r.sharing);
        if (other) { this.banner('share', `${other.name} is already sharing. One screen at a time.`, 'warn', 6000); return; }
        let track;
        try {
            track = await this.media.startScreen();
        } catch (e) {
            const cancelled = e && (e.name === 'NotAllowedError' || e.name === 'AbortError');
            this.banner('share', cancelled ? 'Screen sharing was cancelled.' : 'Screen sharing is not available in this browser.', 'warn', 5000);
            return;
        }
        this.peers.forEach((p) => p.setScreenTrack(track));
        this.announcer.say('You are sharing your screen');
        this.afterLocalChange();
    }

    stopShare() {
        if (!this.media.screenTrack) return;
        this.media.stopScreen();
        this.peers.forEach((p) => p.setScreenTrack(null));
        this.announcer.say('You stopped sharing your screen');
        this.afterLocalChange();
    }

    afterLocalChange() {
        this.syncControls();
        this.renderTiles();
        this.pollNow();
    }

    syncControls() {
        if (this.phase !== 'live') return;
        const el = this.el;
        const set = (b, pressed, label, title, glyph) => {
            b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
            b.setAttribute('aria-label', label);
            b.title = title;
            if (glyph) swapIcon(b, glyph);
        };
        set(el.mic, this.micOn, !this.media.hasAudio ? 'Microphone not available' : this.micOn ? 'Microphone on' : 'Microphone off', this.micOn ? 'Mute (m)' : 'Unmute (m)', this.micOn ? 'mic' : 'mic-off');
        const camPossible = this.media.status.cam === 'ok' || this.media.hasVideo;
        set(el.cam, this.camOn, !camPossible ? 'Camera not available' : this.camOn ? 'Camera on' : 'Camera off', this.camOn ? 'Turn camera off (v)' : 'Turn camera on (v)', this.camOn ? 'video' : 'video-off');
        el.cam.disabled = !camPossible;
        const other = this.roster.find((r) => !r.me && r.sharing);
        set(el.share, this.sharing, this.sharing ? 'Stop sharing your screen' : other ? `${other.name} is sharing` : 'Share your screen', this.sharing ? 'Stop sharing' : 'Share your screen', this.sharing ? 'monitor-x' : 'monitor-up');
        el.share.disabled = !this.media.canShare || (!!other && !this.sharing);
    }

    renderDevices() {
        const m = this.media;
        const build = (id, label, devices, selected, onChange, emptyText) => {
            const select = h('select', { class: 'td-select', id }, devices.length ? devices.map((d, i) => h('option', { value: d.deviceId }, d.label || `${label} ${i + 1}`)) : h('option', { value: '' }, emptyText));
            select.disabled = !devices.length;
            if (selected) select.value = selected;
            select.addEventListener('change', () => onChange(select.value));
            return h('div', { class: 'td-field' }, h('label', { class: 'td-label', for: id }, label), h('span', { class: 'td-select-wrap' }, select, icon('chevron-down')));
        };
        const nodes = [
            build('cr-dev-cam', 'Camera', m.devices.cams, m.camId, async (v) => { try { await m.switchCamera(v); this.announcer.say('Camera changed'); } catch (e) { this.banner('device', 'That camera could not be opened.', 'warn', 6000); } }, 'No camera found'),
            build('cr-dev-mic', 'Microphone', m.devices.mics, m.micId, async (v) => { try { await m.switchMic(v); this.announcer.say('Microphone changed'); } catch (e) { this.banner('device', 'That microphone could not be opened.', 'warn', 6000); } }, 'No microphone found'),
        ];
        if (m.canPickSpeaker) {
            nodes.push(build('cr-dev-spk', 'Speaker', [{ deviceId: '', label: 'Default speaker' }, ...m.devices.spks.filter((d) => d.deviceId !== 'default')], m.spkId, (v) => { m.setSpeaker(v); this.applySink(); this.announcer.say('Speaker changed'); }, 'Default speaker'));
        }
        this.el.devicesPanel.replaceChildren(...nodes);
    }

    onKey(e) {
        if (this.phase !== 'live') return;
        if (e.key === 'Escape' && this.el.devices.open) { this.el.devices.open = false; this.el.devices.querySelector('summary').focus(); return; }
        if (e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target)) return;
        const key = e.key.toLowerCase();
        if (key === 'm') { this.toggleMic(); e.preventDefault(); }
        else if (key === 'v') { this.toggleCam(); e.preventDefault(); }
    }

    // ── Tiles ───────────────────────────────────────────────────────────────

    tileModels() {
        const models = [{
            key: 'me', name: 'You', initials: initials(this.cfg.me), stream: this.camOn ? this.selfStream() : null, mirror: true, muted: true,
            micOff: !this.micOn, role: this.role, note: this.camOn ? '' : (this.media.hasVideo || this.media.status.cam === 'ok' ? 'Camera off' : 'No camera'), status: 'connected',
        }];
        const screens = [];
        for (const r of this.roster) {
            if (r.me) continue;
            const peer = this.peers.get(r.id);
            if (!peer) continue;
            const status = r.state === 'lost' ? 'lost' : peer.status;
            const hasVideo = peer.media.getVideoTracks().some((t) => t.readyState === 'live');
            models.push({
                key: `p${r.id}`, name: r.name, initials: initials(r.name), stream: peer.media, showVideo: status === 'connected' && hasVideo && r.cam !== false,
                muted: false, micOff: r.mic === false, role: r.role, status, quality: status === 'connected' ? peer.quality : null,
                note: r.cam === false ? 'Camera off' : '',
            });
            if (r.sharing && status === 'connected' && peer.screen.getVideoTracks().length) {
                screens.push({ key: `s${r.id}`, name: `${r.name}'s screen`, stream: peer.screen, showVideo: true, muted: true, screen: true, status: 'connected' });
            }
        }
        if (this.sharing && this.media.screenStream) {
            screens.push({ key: 'sme', name: 'Your screen', stream: this.media.screenStream, showVideo: true, muted: true, screen: true, status: 'connected' });
        }
        return [...screens, ...models];
    }

    selfStream() {
        const track = this.media.videoTrack;
        if (!track) return null;
        if (this.selfTrack !== track) { this.selfTrack = track; this.selfStreamObj = new MediaStream([track]); }
        return this.selfStreamObj;
    }

    renderTiles() {
        if (this.phase !== 'live') return;
        const models = this.tileModels();
        this.paint(this.el.grid, models, 'tile');
        this.paint(this.el.strip, models.filter((m) => !m.screen), 'mini');
        this.el.grid.dataset.count = String(Math.min(models.length, 7));
        this.el.grid.dataset.screen = models.some((m) => m.screen) ? 'true' : 'false';
    }

    paint(container, models, kind) {
        if (!container) return;
        const store = this.tiles.get(container) || new Map();
        this.tiles.set(container, store);
        const keys = new Set(models.map((m) => m.key));
        for (const [key, tile] of store) {
            if (!keys.has(key)) { tile.video.srcObject = null; tile.root.remove(); store.delete(key); }
        }
        models.forEach((m, index) => {
            let tile = store.get(m.key);
            if (!tile) {
                const video = h('video', { autoplay: true, playsinline: true });
                const fallback = h('span', { class: 'cr-tile__initials', 'aria-hidden': 'true' });
                const name = h('span', { class: 'cr-tile__name' });
                const mic = h('span', { class: 'cr-tile__mic' }, icon('mic-off'));
                const state = h('span', { class: 'cr-tile__state' });
                const root = h('figure', { class: `cr-tile cr-tile--${kind}` }, video, fallback, h('figcaption', { class: 'cr-tile__bar' }, mic, name, state));
                tile = { root, video, fallback, name, mic, state };
                store.set(m.key, tile);
            }
            const t = tile;
            if (container.children[index] !== t.root) container.insertBefore(t.root, container.children[index] || null);
            t.root.dataset.screen = m.screen ? 'true' : 'false';
            t.root.dataset.status = m.status;
            t.root.dataset.mirror = m.mirror ? 'true' : 'false';
            const show = m.key === 'me' ? !!m.stream : !!m.showVideo;
            if (t.video.srcObject !== (show ? m.stream : null)) t.video.srcObject = show ? m.stream : null;
            t.video.muted = m.muted || kind === 'mini';
            if (show && t.video.paused) t.video.play().catch(() => this.soundBlocked());
            t.video.hidden = !show;
            t.fallback.hidden = show;
            t.fallback.textContent = m.initials || '';
            const roleWord = m.role && m.key !== 'me' ? `, ${ROLE_WORD[m.role] || m.role}` : '';
            t.name.textContent = m.name + (kind === 'tile' ? roleWord : '');
            t.mic.hidden = !m.micOff;
            t.mic.setAttribute('aria-label', 'Microphone off');
            const words = { connecting: 'Connecting', lost: 'Connection lost, trying again', dead: 'Could not connect' }[m.status]
                || (m.quality && m.quality !== 'good' ? `${QUALITY_WORD[m.quality]} connection` : m.note);
            t.state.textContent = words || '';
            t.root.setAttribute('aria-label', [m.name + roleWord, m.micOff ? 'microphone off' : '', words].filter(Boolean).join(', '));
        });
        this.applySink();
    }

    applySink() {
        const id = this.media.spkId;
        if (!id) return;
        this.tiles.forEach((store) => store.forEach((t) => { if (t.video.setSinkId) t.video.setSinkId(id).catch(() => {}); }));
    }

    soundBlocked() {
        this.banner('sound', 'Your browser is holding back the sound of the lesson.', 'warn', 0, [{ label: 'Turn sound on', run: () => {
            resumeShared();
            this.tiles.forEach((store) => store.forEach((t) => t.video.play().catch(() => {})));
            this.clearBanner('sound');
        } }]);
    }

    async sampleAll() {
        if (this.phase !== 'live') return;
        await Promise.all([...this.peers.values()].map((p) => p.sample()));
        this.updateNet();
    }

    updateNet() {
        let q;
        if (this.net === 'reconnecting') q = 'poor';
        else {
            q = worstQuality([...this.peers.values()].filter((p) => p.status === 'connected').map((p) => p.quality));
            if (!q && this.latency > 2500) q = 'fair';
            if (!q && !this.peers.size) q = 'good';
        }
        this.ownQuality = this.net === 'reconnecting' ? this.ownQuality : q;
        const word = this.net === 'reconnecting' ? 'Offline' : q ? `${QUALITY_WORD[q]} connection` : 'Measuring';
        const peer = [...this.peers.values()].find((p) => p.stats);
        this.el.net.dataset.q = q || '';
        this.el.net.querySelector('.cr-net__word').textContent = word;
        this.el.net.title = peer ? `Round trip ${peer.stats.rtt ?? '?'} ms, loss ${peer.stats.loss}%, jitter ${peer.stats.jitter} ms, ${peer.route === 'relay' ? 'through a relay' : 'direct'}` : word;
    }

    // ── People ──────────────────────────────────────────────────────────────

    renderPeople() {
        const list = this.el.people;
        if (!list) return;
        const items = this.roster.map((r) => {
            const peer = this.peers.get(r.id);
            const state = r.me ? 'You' : r.state === 'lost' ? 'Not responding' : peer?.status === 'dead' ? 'Could not connect' : peer?.status === 'connected' ? 'Connected' : 'Connecting';
            const actions = [];
            if (this.role === 'tutor' && !r.me && r.role !== 'tutor') {
                if (r.mic) actions.push(h('button', { type: 'button', class: 'td-btn td-btn--sm td-btn--quiet', onclick: () => this.tutorAction('mute', r) }, icon('volume-x'), h('span', {}, 'Mute')));
                actions.push(h('button', { type: 'button', class: 'td-btn td-btn--sm td-btn--quiet', onclick: () => this.tutorAction('remove', r) }, icon('user-x'), h('span', {}, 'Remove')));
            }
            return h('li', { class: 'cr-list__row cr-person' },
                h('span', { class: 'cr-person__who' }, h('span', { class: 'td-avatar td-avatar--sm', 'aria-hidden': 'true' }, initials(r.name)),
                    h('span', {}, h('span', { class: 'cr-person__name' }, r.me ? `${r.name} (you)` : r.name), h('span', { class: 'cr-muted' }, `${ROLE_WORD[r.role] || r.role}. ${state}.${r.mic ? '' : ' Microphone off.'}`))),
                actions.length ? h('span', { class: 'cr-person__actions' }, actions) : null);
        });
        list.replaceChildren(...items);
    }

    async tutorAction(action, r) {
        if (action === 'remove' && !window.confirm(`Remove ${r.name} from this lesson? They cannot come back into this lesson.`)) return;
        try {
            await this.api.post(this.urls.tutor, { action, attendee_id: r.id });
            this.announcer.say(action === 'mute' ? `${r.name} muted` : `${r.name} removed`);
            this.pollNow();
        } catch (e) {
            this.banner('tutor', e.message || 'That did not work.', 'warn', 6000);
        }
    }

    // ── Chat ────────────────────────────────────────────────────────────────

    renderChat(messages) {
        const log = this.el.chatLog;
        for (const m of messages) {
            this.chatSince = Math.max(this.chatSince, m.id);
            if (log.querySelector(`[data-id="${m.id}"]`)) continue;
            const report = m.mine || m.hidden ? null : h('button', { type: 'button', class: 'cr-link-btn', onclick: () => this.reportMessage(m) }, icon('flag'), h('span', {}, 'Report'));
            log.append(h('li', { class: `cr-msg${m.mine ? ' cr-msg--mine' : ''}`, 'data-id': m.id },
                h('p', { class: 'cr-msg__meta' }, h('strong', {}, m.mine ? 'You' : m.from), h('time', { datetime: m.at }, fmtTime(m.at)), report),
                h('p', { class: 'cr-msg__body' }, m.hidden ? 'A moderator removed this message.' : m.body),
                m.redacted ? h('p', { class: 'cr-msg__note' }, 'Contact details were hidden.') : null));
            if (!m.mine && this.rosterReady) this.announcer.say(`Message from ${m.from}`);
        }
        log.scrollTop = log.scrollHeight;
    }

    async sendChat() {
        const body = this.el.chatInput.value.trim();
        if (!body) return;
        this.el.chatInput.disabled = true;
        try {
            const reply = await this.api.post(this.urls.chat, { body });
            this.el.chatInput.value = '';
            this.renderChat([reply.message]);
        } catch (e) {
            this.banner('chat', e.message || 'The message was not sent.', 'warn', 6000);
        } finally {
            this.el.chatInput.disabled = false;
            this.el.chatInput.focus();
        }
    }

    reportMessage(m) {
        const id = `cr-report-${m.id}`;
        const item = this.el.chatLog.querySelector(`[data-id="${m.id}"]`);
        if (!item || item.querySelector('form')) return;
        const select = h('select', { class: 'td-select', id }, REPORT_CATEGORIES.map(([v, l]) => h('option', { value: v }, l)));
        const details = h('textarea', { class: 'td-textarea', rows: 2, maxlength: 2000, 'aria-label': 'What happened (optional)', placeholder: 'What happened (optional)' });
        const form = h('form', { class: 'cr-report' },
            h('label', { class: 'td-label', for: id }, 'Why are you reporting this message?'), h('span', { class: 'td-select-wrap' }, select, icon('chevron-down')), details,
            h('div', { class: 'cr-actions' }, h('button', { type: 'submit', class: 'td-btn td-btn--sm td-btn--danger' }, h('span', {}, 'Send the report')),
                h('button', { type: 'button', class: 'td-btn td-btn--sm td-btn--quiet', onclick: () => form.remove() }, h('span', {}, 'Cancel'))));
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const reply = await this.api.post(this.urls.report.replace('__ID__', String(m.id)), { category: select.value, details: details.value });
                form.replaceWith(h('p', { class: 'cr-msg__note', role: 'status' }, reply.message || 'Reported.'));
            } catch (err) {
                this.banner('report', err.message || 'The report was not sent.', 'warn', 6000);
            }
        });
        item.append(form);
        select.focus();
    }

    // ── Shared notes ────────────────────────────────────────────────────────

    receiveNotes(notes) {
        const el = this.el.notes;
        if (this.notesDirty || this.notesSaving) {
            if (notes.version !== this.notesVersion) this.serverNotes = notes;
            return;
        }
        this.notesVersion = notes.version;
        if (el.value !== notes.body) el.value = notes.body;
    }

    notesChanged() {
        this.notesDirty = true;
        this.el.notesState.textContent = 'Not saved yet';
        clearTimeout(this.timers.notes);
        this.timers.notes = setTimeout(() => this.saveNotes(), NOTES_SAVE_MS);
    }

    async saveNotes() {
        if (this.notesSaving) { this.timers.notes = setTimeout(() => this.saveNotes(), 400); return; }
        this.notesSaving = true;
        const body = this.el.notes.value;
        this.el.notesState.textContent = 'Saving';
        try {
            const reply = await this.api.post(this.urls.notes, { body, version: Math.max(0, this.notesVersion) });
            this.notesVersion = reply.version;
            if (this.el.notes.value === body) { this.notesDirty = false; this.el.notesState.textContent = 'Saved'; }
            this.el.notesConflict.hidden = true;
        } catch (e) {
            if (e instanceof ApiError && e.code === 'notes_conflict') {
                this.el.notesMine.value = body;
                this.el.notesConflict.hidden = false;
                this.notesVersion = e.data.version;
                this.el.notes.value = e.data.body;
                this.notesDirty = false;
                this.el.notesState.textContent = 'Someone else saved first';
                this.announcer.say('Someone else saved the notes first. Your text is kept below the box.');
            } else {
                this.el.notesState.textContent = e.message || 'Not saved';
            }
        } finally {
            this.notesSaving = false;
        }
    }

    // ── Files ───────────────────────────────────────────────────────────────

    renderFiles(files) {
        const list = this.el.files;
        if (!files.length) {
            list.replaceChildren(h('li', { class: 'cr-muted' }, 'No files yet. Files shared here stay with the lesson.'));
            return;
        }
        list.replaceChildren(...files.map((f) => h('li', { class: 'cr-list__row' },
            h('a', { class: 'td-link', href: f.url }, icon('download'), h('span', {}, f.title)),
            h('span', { class: 'cr-muted' }, `${String(f.type || '').toUpperCase()}${f.by ? `, ${f.by}` : ''}`))));
    }

    async uploadFile() {
        const file = this.el.file.files[0];
        if (!file) { this.el.uploadMsg.textContent = 'Choose a file first.'; return; }
        const form = new FormData();
        form.append('file', file);
        if (this.el.fileTitle.value.trim()) form.append('title', this.el.fileTitle.value.trim());
        this.el.uploadMsg.textContent = 'Uploading';
        try {
            const reply = await this.api.upload(this.urls.upload, form);
            this.el.uploadMsg.textContent = `Shared: ${reply.title}`;
            this.el.upload.reset();
            this.renderFiles(reply.files || []);
            this.filesSince = -1;
        } catch (e) {
            this.el.uploadMsg.textContent = e.message || 'The file was not shared.';
        }
    }

    // ── Banners ─────────────────────────────────────────────────────────────

    banner(key, text, tone = 'info', ttl = 0, actions = []) {
        clearTimeout(this.banners.get(key)?.timer);
        const node = h('div', { class: `cr-banner__item td-notice td-notice--${tone === 'warn' ? 'warning' : tone}` },
            h('p', { class: 'cr-banner__text' }, text),
            actions.length ? h('div', { class: 'cr-actions' }, actions.map((a) => (a.href
                ? h('a', { class: 'td-btn td-btn--sm', href: a.href, target: '_blank', rel: 'noopener noreferrer' }, h('span', {}, a.label), icon('external-link'))
                : h('button', { type: 'button', class: 'td-btn td-btn--sm', onclick: a.run }, h('span', {}, a.label))))) : null);
        const old = this.banners.get(key);
        if (old) old.node.replaceWith(node); else this.el.banner.append(node);
        const entry = { node, timer: ttl ? setTimeout(() => this.clearBanner(key), ttl) : null };
        this.banners.set(key, entry);
        this.el.banner.hidden = false;
    }

    clearBanner(key) {
        const entry = this.banners.get(key);
        if (!entry) return;
        clearTimeout(entry.timer);
        entry.node.remove();
        this.banners.delete(key);
        if (!this.banners.size) this.el.banner.hidden = true;
    }

    // ── Leaving ─────────────────────────────────────────────────────────────

    async leave() {
        if (this.stopped) return;
        this.el.leave.disabled = true;
        try { await Promise.race([this.api.post(this.urls.leave, {}, { keepalive: true }), sleep(2500)]); } catch (e) { /* leaving anyway */ }
        this.teardown();
        window.location.assign(this.urls.after);
    }

    async endForEveryone() {
        if (!window.confirm('End the lesson for everyone? Learners leave the classroom; you can come back in until it closes.')) return;
        try {
            await this.api.post(this.urls.end, {});
        } catch (e) {
            this.banner('end', e.message || 'The lesson could not be ended.', 'warn', 6000);
            return;
        }
        this.teardown();
        window.location.assign(this.urls.after);
    }

    teardown() {
        this.stopped = true;
        clearTimeout(this.pollTimer);
        Object.values(this.timers).forEach((t) => { clearTimeout(t); clearInterval(t); });
        this.timers = {};
        this.sendQueue = [];
        this.peers.forEach((p) => p.close());
        this.peers.clear();
        this.tiles.forEach((store) => store.forEach((t) => { t.video.srcObject = null; }));
        this.lobby.stop();
        this.board?.destroy();
        this.media.stopScreen();
        this.media.stopAll();
        closeShared();
        this.ac.abort();
    }

    finish(kind, detail) {
        if (this.phase === 'ended') return;
        this.teardown();
        this.phase = 'ended';
        const copy = {
            ended: ['The lesson has ended', 'The tutor ended the lesson, or the classroom closed.'],
            removed: ['The tutor removed you from this lesson', 'You cannot come back into this lesson. If something worried you, report it to Dot.Tutor support.'],
            displaced: ['You opened this lesson in another window', 'Only one window can be in the lesson at a time, so this one stopped.'],
            expired: ['Your session expired', 'Reload this page to continue.'],
            refused: ['You cannot be in this lesson now', detail || 'Something stopped you joining.'],
        }[kind] || ['You left the lesson', ''];
        this.el.live.hidden = true;
        this.el.lobby.hidden = true;
        this.setStatus(kind === 'ended' ? 'Ended' : 'Not in the lesson', 'idle');
        const actions = [h('a', { class: 'td-btn td-btn--primary', href: this.urls.after }, h('span', {}, 'Open the lesson record'))];
        if (kind === 'displaced' || kind === 'expired') actions.unshift(h('button', { type: 'button', class: 'td-btn', onclick: () => window.location.reload() }, h('span', {}, 'Use this window')));
        this.el.notice.replaceChildren(h('h2', { class: 'cr-h2', tabindex: '-1' }, copy[0]), h('p', {}, copy[1]), h('div', { class: 'cr-actions' }, actions));
        this.el.notice.hidden = false;
        this.el.notice.querySelector('h2').focus();
        this.announcer.say(copy[0]);
    }
}

const root = document.getElementById('classroom-root');
if (root && document.getElementById('cr-lobby')) {
    window.__classroom = new Classroom(root);
}
