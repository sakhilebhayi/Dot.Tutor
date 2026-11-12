// Adapted from Dot.Engage (resources/js/meeting/audio.js, R-39), copied into Dot.Tutor, not imported across repos.
// Audio level measurement. The lobby meter owns its own AudioContext (closed when the lobby is left); everyone in
// the call shares one context so six analysers do not mean six audio contexts.

let shared = null;

function makeContext() {
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) return null;
    try { return new Ctor({ latencyHint: 'interactive' }); } catch (e) { return null; }
}

export function sharedContext() {
    if (!shared || shared.state === 'closed') shared = makeContext();
    return shared;
}

export function resumeShared() {
    const ctx = sharedContext();
    if (ctx && ctx.state === 'suspended') ctx.resume().catch(() => {});
}

export function closeShared() {
    if (shared && shared.state !== 'closed') shared.close().catch(() => {});
    shared = null;
}

/** Measures the loudness of a stream. level is 0..1 on a decibel scale; rms is the raw figure. */
export class LevelMeter {
    constructor(stream, { ownContext = false, interval = 90, onLevel = () => {} } = {}) {
        this.onLevel = onLevel;
        this.ownContext = ownContext;
        this.ctx = ownContext ? makeContext() : sharedContext();
        this.timer = null;
        this.level = 0;
        this.rms = 0;
        this.source = null;
        this.analyser = null;
        if (!this.ctx || !stream || !stream.getAudioTracks().length) return;
        try {
            this.source = this.ctx.createMediaStreamSource(stream);
            this.analyser = this.ctx.createAnalyser();
            this.analyser.fftSize = 512;
            this.analyser.smoothingTimeConstant = 0.4;
            this.source.connect(this.analyser);
            this.buffer = new Float32Array(this.analyser.fftSize);
            this.timer = setInterval(() => this.sample(), interval);
        } catch (e) {
            this.stop();
        }
    }

    sample() {
        if (!this.analyser) return;
        this.analyser.getFloatTimeDomainData(this.buffer);
        let sum = 0;
        for (let i = 0; i < this.buffer.length; i++) sum += this.buffer[i] * this.buffer[i];
        this.rms = Math.sqrt(sum / this.buffer.length);
        const db = this.rms > 0 ? 20 * Math.log10(this.rms) : -100;
        this.level = Math.min(1, Math.max(0, (db + 60) / 50));
        this.onLevel(this.level, this.rms);
    }

    stop() {
        clearInterval(this.timer);
        this.timer = null;
        try { this.source?.disconnect(); } catch (e) { /* already gone */ }
        try { this.analyser?.disconnect(); } catch (e) { /* already gone */ }
        this.source = null;
        this.analyser = null;
        if (this.ownContext && this.ctx && this.ctx.state !== 'closed') this.ctx.close().catch(() => {});
        this.ctx = null;
    }
}
