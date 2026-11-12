// Adapted from Dot.Engage (resources/js/meeting/peer.js, R-39), copied into Dot.Tutor, not imported across repos.
// One person on the other end of a mesh link. Implements the "perfect negotiation" pattern: either side may offer at
// any time; the polite side (the higher attendee id) backs out of a collision. On top of that: ICE restart when the
// path drops, a full rebuild when restarts do not help, and a hard stop after a minute with a Retry.

const RESTART_AFTER_DISCONNECT_MS = 4000;
const LOST_GRACE_MS = 1500;
const WATCHDOG_MS = 10000;
const FIRST_CONNECT_MS = 12000;
const GIVE_UP_MS = 60000;
const POLITE_DEFER_MS = 5000;
const CANDIDATE_BATCH_MS = 60;
const CANDIDATES_PER_SIGNAL = 40;

export class Peer {
    /**
     * ctx: { myId, sid, iceServers(), media (LocalMedia), send(type, to, payloadString), changed(peer, what), log(...) }
     */
    constructor(ctx, remote, { fromOffer = false } = {}) {
        this.ctx = ctx;
        this.id = remote.id;
        this.remote = remote;
        this.polite = ctx.myId > remote.id;
        this.gen = 0;
        this.remoteSid = null;
        this.status = 'connecting'; // connecting | connected | lost | dead
        this.connectedOnce = false;
        this.quality = null;
        this.stats = null;
        this.route = null;
        this.closed = false;
        this.chain = Promise.resolve();
        this.attempts = 0;
        this.troubleSince = Date.now();
        this.rebuilds = 0;
        this.remoteErrors = 0;
        this.qualityStreak = { value: null, count: 0 };
        this.timers = {};
        // Created because they offered: just answer. Otherwise the impolite side opens, and the polite side waits a
        // few seconds for that offer before opening itself.
        this.build({ initiator: !fromOffer && !this.polite });
        if (!fromOffer && this.polite) this.deferOffer();
    }

    get name() { return this.remote.name; }

    // ── Building and tearing down ────────────────────────────────────────────

    build({ initiator = true } = {}) {
        this.teardownPc();
        clearTimeout(this.timers.defer);
        this.timers.defer = null;
        const pc = new RTCPeerConnection({ iceServers: this.ctx.iceServers(), bundlePolicy: 'max-bundle' });
        this.pc = pc;
        this.makingOffer = false;
        this.ignoreOffer = false;
        this.settingRemoteAnswerPending = false;
        this.hasRemote = false;
        this.firstNegotiated = false;
        this.initiator = false;
        this.pendingCandidates = [];
        this.candidateBuffer = [];
        this.media = new MediaStream();
        this.screen = new MediaStream();
        this.camStreamId = null;
        this.prevStats = null;
        this.audioTx = this.videoTx = this.screenTx = null;

        pc.ontrack = (event) => {
            if (pc !== this.pc) return;
            const streamId = event.streams[0] && event.streams[0].id;
            let camera;
            if (streamId) {
                // The first stream that shows up is the camera and microphone; any other one is a shared screen.
                if (!this.camStreamId) this.camStreamId = streamId;
                camera = streamId === this.camStreamId;
            } else {
                const videos = pc.getTransceivers().filter((t) => t.receiver.track.kind === 'video');
                camera = event.track.kind === 'audio' || videos[0] === event.transceiver;
            }
            const target = camera ? this.media : this.screen;
            if (!target.getTracks().includes(event.track)) target.addTrack(event.track);
            this.ctx.changed(this, 'tracks');
        };
        pc.onicecandidate = (event) => {
            if (pc !== this.pc) return;
            if (event.candidate) {
                this.candidateBuffer.push(event.candidate.toJSON());
                if (!this.timers.flush) this.timers.flush = setTimeout(() => this.flushCandidates(false), CANDIDATE_BATCH_MS);
            } else {
                this.flushCandidates(true);
            }
        };
        pc.onnegotiationneeded = () => {
            if (pc !== this.pc) return;
            // A connection that has not been told to open yet waits for the other side's offer.
            if (!this.initiator && !this.hasRemote && !this.firstNegotiated) return;
            this.negotiate();
        };
        const onState = () => this.onConnectionState(pc);
        pc.onconnectionstatechange = onState;
        pc.oniceconnectionstatechange = onState;

        this.armWatchdog(FIRST_CONNECT_MS);
        if (initiator) this.becomeInitiator();
        this.ctx.changed(this, 'rebuilt');
    }

    /** Add our own audio and video lines to the connection; this is what makes it send the first offer. */
    becomeInitiator() {
        const pc = this.pc;
        if (!pc || this.initiator) return;
        this.initiator = true;
        const local = this.ctx.media;
        this.audioTx = pc.addTransceiver(local.audioTrack || 'audio', { direction: 'sendrecv', streams: [local.camStream] });
        this.videoTx = pc.addTransceiver(local.videoTrack || 'video', { direction: 'sendrecv', streams: [local.camStream] });
        if (local.screenTrack) this.screenTx = pc.addTransceiver(local.screenTrack, { direction: 'sendonly', streams: [local.screenStream] });
    }

    deferOffer() {
        clearTimeout(this.timers.defer);
        this.timers.defer = setTimeout(() => {
            this.timers.defer = null;
            if (!this.closed && !this.hasRemote && !this.firstNegotiated) this.becomeInitiator();
        }, POLITE_DEFER_MS);
    }

    /**
     * We answered an offer on a connection that had no lines of its own: our microphone and camera must ride on the
     * lines the other side created (a line made by addTransceiver is never matched to an incoming offer).
     */
    adoptLines(before) {
        const pc = this.pc;
        if (!pc || this.firstNegotiated) return;
        const local = this.ctx.media;
        const created = pc.getTransceivers().filter((t) => !before.has(t));
        for (const kind of ['audio', 'video']) {
            const line = created.find((t) => t.receiver.track.kind === kind);
            if (!line) continue;
            const key = kind === 'audio' ? 'audioTx' : 'videoTx';
            if (this[key] && this[key] !== line) { try { this[key].stop(); } catch (e) { /* already stopped */ } }
            this[key] = line;
            line.direction = 'sendrecv';
            try { line.sender.setStreams(local.camStream); } catch (e) { /* older browsers: the other side finds the camera by order */ }
            const track = kind === 'audio' ? local.audioTrack : local.videoTrack;
            if (track) line.sender.replaceTrack(track).catch(() => {});
        }
        if (local.screenTrack) setTimeout(() => this.setScreenTrack(local.screenTrack), 0);
    }

    teardownPc() {
        const pc = this.pc;
        this.pc = null;
        if (!pc) return;
        pc.ontrack = pc.onicecandidate = pc.onnegotiationneeded = pc.onconnectionstatechange = pc.oniceconnectionstatechange = null;
        try { pc.close(); } catch (e) { /* already closed */ }
    }

    close() {
        this.closed = true;
        for (const key of Object.keys(this.timers)) clearTimeout(this.timers[key]);
        this.timers = {};
        this.teardownPc();
    }

    /** Start over with a brand new connection object (the "full renegotiation" fallback). */
    rebuild() {
        this.gen += 1;
        this.rebuilds += 1;
        this.hasRemote = false;
        this.build({ initiator: true });
        this.ctx.log('rebuilt link', this.id, 'generation', this.gen);
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    send(type, extra) {
        this.ctx.send(type, this.id, JSON.stringify({ s: this.ctx.sid, t: this.remoteSid, g: this.gen, ...extra }));
    }

    flushCandidates(end) {
        clearTimeout(this.timers.flush);
        this.timers.flush = null;
        if (this.closed || !this.candidateBuffer.length && !end) return;
        do {
            const batch = this.candidateBuffer.splice(0, CANDIDATES_PER_SIGNAL);
            const last = this.candidateBuffer.length === 0;
            this.send('candidate', { c: batch, end: !!end && last });
        } while (this.candidateBuffer.length);
    }

    async negotiate() {
        const pc = this.pc;
        if (!pc || this.closed) return;
        try {
            this.makingOffer = true;
            await pc.setLocalDescription();
            const ld = pc.localDescription;
            if (pc !== this.pc || !ld) return;
            this.send(ld.type, { d: { type: ld.type, sdp: ld.sdp } });
        } catch (e) {
            this.ctx.log('offer failed', this.id, e && e.message);
        } finally {
            if (pc === this.pc) this.makingOffer = false;
        }
    }

    // ── Receiving ────────────────────────────────────────────────────────────

    receive(type, payload) {
        this.chain = this.chain.then(() => this.handle(type, payload)).catch((e) => this.ctx.log('signal failed', this.id, e && e.message));
        return this.chain;
    }

    async handle(type, p) {
        if (this.closed || !p) return;
        if (p.t && p.t !== this.ctx.sid) return; // addressed to an earlier visit of ours

        const theirGen = Number(p.g) || 0;
        if (this.remoteSid && p.s !== this.remoteSid) {
            if (type !== 'offer') return; // a late message from their earlier visit
            // They reloaded or came back: a new session, so a new connection.
            this.remoteSid = p.s;
            this.gen = theirGen;
            this.hasRemote = false;
            this.build({ initiator: false });
        } else if (!this.remoteSid) {
            this.remoteSid = p.s;
        }
        if (theirGen < this.gen) return;
        if (theirGen > this.gen) {
            if (type !== 'offer') return;
            this.gen = theirGen;
            this.hasRemote = false;
            this.build({ initiator: false });
        }

        const pc = this.pc;
        if (!pc) return;

        if (type === 'candidate') {
            for (const candidate of p.c || []) {
                if (!pc.remoteDescription) { this.pendingCandidates.push(candidate); continue; }
                try { await pc.addIceCandidate(candidate); } catch (e) { if (!this.ignoreOffer) this.ctx.log('candidate rejected', this.id, e && e.message); }
            }
            return;
        }

        if (type !== 'offer' && type !== 'answer') return;
        const desc = p.d;
        if (!desc || typeof desc.sdp !== 'string') return;
        const isOffer = type === 'offer';
        if (!isOffer && pc.signalingState !== 'have-local-offer') return; // a repeated or stale answer

        const readyForOffer = !this.makingOffer && (pc.signalingState === 'stable' || this.settingRemoteAnswerPending);
        const collision = isOffer && !readyForOffer;
        this.ignoreOffer = !this.polite && collision;
        if (this.ignoreOffer) return;

        const before = new Set(pc.getTransceivers());
        try {
            this.settingRemoteAnswerPending = !isOffer;
            await pc.setRemoteDescription({ type, sdp: desc.sdp });
        } catch (e) {
            this.remoteErrors += 1;
            this.ctx.log('remote description failed', this.id, e && e.message);
            if (this.remoteErrors >= 2 && pc === this.pc) { this.remoteErrors = 0; this.rebuild(); }
            return;
        } finally {
            if (pc === this.pc) this.settingRemoteAnswerPending = false;
        }
        if (pc !== this.pc) return;
        this.remoteErrors = 0;
        this.hasRemote = true;
        if (isOffer) this.adoptLines(before);
        else this.firstNegotiated = true;

        const queued = this.pendingCandidates.splice(0);
        for (const candidate of queued) {
            try { await pc.addIceCandidate(candidate); } catch (e) { /* late or duplicate */ }
        }

        if (isOffer) {
            await pc.setLocalDescription();
            const ld = pc.localDescription;
            if (pc === this.pc && ld) {
                this.firstNegotiated = true;
                this.send('answer', { d: { type: ld.type, sdp: ld.sdp } });
            }
        }
    }

    // ── Staying connected ────────────────────────────────────────────────────

    onConnectionState(pc) {
        if (pc !== this.pc || this.closed) return;
        const state = pc.connectionState || pc.iceConnectionState;
        if (state === 'connected' || pc.iceConnectionState === 'completed') {
            this.markConnected();
        } else if (state === 'disconnected') {
            this.markTrouble(false);
        } else if (state === 'failed') {
            this.markTrouble(true);
        }
    }

    markConnected() {
        clearTimeout(this.timers.restart);
        clearTimeout(this.timers.lost);
        clearTimeout(this.timers.watchdog);
        this.timers.restart = this.timers.lost = this.timers.watchdog = null;
        this.attempts = 0;
        this.remoteErrors = 0;
        this.troubleSince = null;
        if (this.status !== 'connected') {
            const wasLost = this.connectedOnce;
            this.status = 'connected';
            this.connectedOnce = true;
            this.ctx.changed(this, wasLost ? 'recovered' : 'connected');
            this.sample();
        }
    }

    markTrouble(failed) {
        if (this.troubleSince === null) this.troubleSince = Date.now();
        if (this.status === 'dead') return;
        const becomeLost = () => {
            this.timers.lost = null;
            if (this.pc && !this.closed && this.status !== 'dead' && this.pc.connectionState !== 'connected') {
                if (this.connectedOnce && this.status !== 'lost') {
                    this.status = 'lost';
                    this.ctx.changed(this, 'lost');
                }
            }
        };
        clearTimeout(this.timers.lost);
        if (failed) becomeLost(); else this.timers.lost = setTimeout(becomeLost, LOST_GRACE_MS);
        clearTimeout(this.timers.restart);
        this.timers.restart = setTimeout(() => this.recover(), failed ? 0 : RESTART_AFTER_DISCONNECT_MS);
    }

    armWatchdog(ms) {
        clearTimeout(this.timers.watchdog);
        this.timers.watchdog = setTimeout(() => this.recover(), ms);
    }

    recover() {
        clearTimeout(this.timers.restart);
        this.timers.restart = null;
        const pc = this.pc;
        if (this.closed || this.status === 'dead' || !pc || pc.connectionState === 'connected') return;
        if (this.troubleSince === null) this.troubleSince = Date.now();
        if (Date.now() - this.troubleSince > GIVE_UP_MS) { this.giveUp(); return; }

        this.attempts += 1;
        if (this.attempts <= 2 && this.hasRemote) {
            this.ctx.log('ice restart', this.id, 'attempt', this.attempts);
            try { pc.restartIce(); } catch (e) { this.negotiate(); }
        } else {
            this.rebuild();
        }
        this.armWatchdog(WATCHDOG_MS);
    }

    giveUp() {
        clearTimeout(this.timers.watchdog);
        clearTimeout(this.timers.restart);
        clearTimeout(this.timers.lost);
        this.status = 'dead';
        this.teardownPc();
        this.ctx.changed(this, 'dead');
    }

    /** The person pressed Retry, or the network came back after we gave up. */
    retry() {
        if (this.closed) return;
        this.troubleSince = Date.now();
        this.attempts = 0;
        this.status = this.connectedOnce ? 'lost' : 'connecting';
        this.rebuild();
        this.armWatchdog(FIRST_CONNECT_MS);
        this.ctx.changed(this, 'retry');
    }

    /** The browser came back online or the tab woke up: do not wait for the timers. */
    kick() {
        if (this.closed || this.status === 'connected') return;
        if (this.status === 'dead') { this.retry(); return; }
        this.attempts = Math.min(this.attempts, 1);
        clearTimeout(this.timers.restart);
        this.timers.restart = setTimeout(() => this.recover(), 300);
    }

    // ── Local tracks ─────────────────────────────────────────────────────────

    setLocalTrack(kind, track) {
        const tx = kind === 'audio' ? this.audioTx : this.videoTx;
        if (!tx || !this.pc) return;
        tx.sender.replaceTrack(track).catch((e) => this.ctx.log('replaceTrack failed', this.id, e && e.message));
    }

    setScreenTrack(track) {
        if (!this.pc) return;
        if (this.screenTx) {
            this.screenTx.sender.replaceTrack(track).catch((e) => this.ctx.log('screen replaceTrack failed', this.id, e && e.message));
        } else if (track) {
            this.screenTx = this.pc.addTransceiver(track, { direction: 'sendonly', streams: [this.ctx.media.screenStream] });
        }
    }

    /** Many peers means each one gets a smaller share of the uplink. */
    async applyBandwidth(peerCount) {
        const budget = peerCount <= 2 ? 1_200_000 : peerCount <= 4 ? 600_000 : 320_000;
        const scale = peerCount >= 4 ? 2 : 1;
        for (const [tx, bps, down] of [[this.videoTx, budget, scale], [this.screenTx, Math.max(500_000, budget), 1]]) {
            if (!tx || !this.pc) continue;
            try {
                const params = tx.sender.getParameters();
                if (!params.encodings || !params.encodings.length) params.encodings = [{}];
                if (params.encodings[0].maxBitrate === bps && (params.encodings[0].scaleResolutionDownBy || 1) === down) continue;
                params.encodings[0].maxBitrate = bps;
                params.encodings[0].scaleResolutionDownBy = down;
                await tx.sender.setParameters(params);
            } catch (e) { /* not supported before negotiation finishes; the next call tries again */ }
        }
    }

    // ── Measuring ────────────────────────────────────────────────────────────

    async sample() {
        const pc = this.pc;
        if (!pc || this.status !== 'connected') return;
        let reports;
        try { reports = await pc.getStats(); } catch (e) { return; }
        if (pc !== this.pc) return;

        const byId = new Map();
        reports.forEach((r) => byId.set(r.id, r));
        let rtt = null;
        let jitter = 0;
        let lost = 0;
        let received = 0;
        let bytes = 0;
        let frames = 0;
        let remoteLoss = null;
        let pair = null;
        reports.forEach((r) => {
            if (r.type === 'inbound-rtp' && !r.isRemote) {
                jitter = Math.max(jitter, (r.jitter || 0) * 1000);
                lost += Math.max(0, r.packetsLost || 0);
                received += r.packetsReceived || 0;
                bytes += r.bytesReceived || 0;
                if (r.kind === 'video') frames += r.framesDecoded || 0;
            } else if (r.type === 'remote-inbound-rtp') {
                if (typeof r.roundTripTime === 'number') rtt = Math.max(rtt ?? 0, r.roundTripTime * 1000);
                if (typeof r.fractionLost === 'number') remoteLoss = Math.max(remoteLoss ?? 0, r.fractionLost * 100);
            } else if (r.type === 'transport' && r.selectedCandidatePairId) {
                pair = byId.get(r.selectedCandidatePairId) || pair;
            } else if (r.type === 'candidate-pair' && r.nominated && r.state === 'succeeded' && !pair) {
                pair = r;
            }
        });
        if (pair) {
            if (typeof pair.currentRoundTripTime === 'number') rtt = pair.currentRoundTripTime * 1000;
            const local = byId.get(pair.localCandidateId);
            if (local) this.route = local.candidateType === 'relay' ? 'relay' : 'direct';
        }

        const prev = this.prevStats;
        let loss = 0;
        if (prev) {
            const dLost = Math.max(0, lost - prev.lost);
            const dRecv = Math.max(0, received - prev.received);
            loss = dLost + dRecv > 0 ? (dLost / (dLost + dRecv)) * 100 : 0;
        }
        if (remoteLoss !== null) loss = Math.max(loss, remoteLoss);
        this.prevStats = { lost, received };
        this.stats = { rtt: rtt === null ? null : Math.round(rtt), loss: Math.round(loss * 10) / 10, jitter: Math.round(jitter), bytes, frames };

        let raw = 'good';
        if ((rtt ?? 0) > 400 || loss > 10 || jitter > 100) raw = 'poor';
        else if ((rtt ?? 0) > 200 || loss > 3 || jitter > 40) raw = 'fair';
        // Two samples in a row before the word changes, so it does not flicker.
        if (raw === this.qualityStreak.value) this.qualityStreak.count += 1; else this.qualityStreak = { value: raw, count: 1 };
        if (this.quality === null || this.qualityStreak.count >= 2) {
            if (raw !== this.quality) { this.quality = raw; this.ctx.changed(this, 'quality'); }
        }
    }
}
