// Adapted from Dot.Engage (resources/js/meeting/media.js, R-39), copied into Dot.Tutor, not imported across repos.
// The person's own camera, microphone, speaker choice and screen. Everything degrades: no camera means audio
// only, no microphone means listening only, neither means a viewer. Nothing here talks to the network.

const VIDEO = { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30, max: 30 } };
const AUDIO = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };

/** Why a device is not available, in a word the interface can use. */
export function classify(error) {
    const name = error && error.name;
    if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') return 'denied';
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') return 'none';
    if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') return 'busy';
    if (name === 'OverconstrainedError') return 'overconstrained';
    return 'error';
}

export class LocalMedia {
    constructor() {
        this.audioTrack = null;
        this.videoTrack = null;
        this.screenTrack = null;
        // Persistent streams give every peer connection a stable stream id; the tracks inside change by replaceTrack.
        this.camStream = new MediaStream();
        this.screenStream = new MediaStream();
        this.micOn = true;
        this.camOn = true;
        this.status = { cam: 'pending', mic: 'pending' };
        this.devices = { cams: [], mics: [], spks: [] };
        this.camId = '';
        this.micId = '';
        this.spkId = '';
        this.listeners = new Map();
        this.onDeviceChange = () => this.refreshDevices().then(() => this.emit('devices'));
        navigator.mediaDevices?.addEventListener?.('devicechange', this.onDeviceChange);
    }

    on(name, fn) {
        if (!this.listeners.has(name)) this.listeners.set(name, new Set());
        this.listeners.get(name).add(fn);
        return () => this.listeners.get(name)?.delete(fn);
    }

    emit(name, ...args) {
        for (const fn of this.listeners.get(name) || []) {
            try { fn(...args); } catch (e) { console.warn('media listener failed', e); }
        }
    }

    get supported() { return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia); }
    get canShare() { return !!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia); }
    get canPickSpeaker() { return typeof HTMLMediaElement !== 'undefined' && 'setSinkId' in HTMLMediaElement.prototype; }
    get hasAudio() { return !!this.audioTrack && this.audioTrack.readyState === 'live'; }
    get hasVideo() { return !!this.videoTrack && this.videoTrack.readyState === 'live'; }

    /** A stream with just the camera picture, for the self view (never the microphone, so it cannot echo). */
    previewStream() {
        return this.hasVideo ? new MediaStream([this.videoTrack]) : null;
    }

    async refreshDevices() {
        if (!navigator.mediaDevices?.enumerateDevices) return this.devices;
        try {
            const list = await navigator.mediaDevices.enumerateDevices();
            this.devices = {
                cams: list.filter((d) => d.kind === 'videoinput'),
                mics: list.filter((d) => d.kind === 'audioinput'),
                spks: list.filter((d) => d.kind === 'audiooutput'),
            };
        } catch (e) { /* keep the last list */ }
        // Remember what is actually in use.
        const camSetting = this.videoTrack?.getSettings?.().deviceId;
        const micSetting = this.audioTrack?.getSettings?.().deviceId;
        if (camSetting) this.camId = camSetting;
        if (micSetting) this.micId = micSetting;
        return this.devices;
    }

    async openOne(kind, deviceId) {
        const constraints = kind === 'video'
            ? { video: deviceId ? { ...VIDEO, deviceId: { exact: deviceId } } : VIDEO }
            : { audio: deviceId ? { ...AUDIO, deviceId: { exact: deviceId } } : AUDIO };
        try {
            return await navigator.mediaDevices.getUserMedia(constraints);
        } catch (e) {
            // A remembered device that has been unplugged: fall back to the default one once.
            if (deviceId && classify(e) === 'overconstrained') return this.openOne(kind, '');
            throw e;
        }
    }

    /**
     * Ask for the camera and microphone. Tries both together (one prompt), then each alone, so a missing or
     * blocked device does not take the other one with it. Sets this.status.{cam,mic} to ok | denied | none | busy | error | unsupported.
     */
    async acquire() {
        this.status = { cam: 'pending', mic: 'pending' };
        if (!this.supported) {
            this.status = { cam: 'unsupported', mic: 'unsupported' };
            return this.status;
        }

        await this.refreshDevices();
        const knownDevices = this.devices.cams.length + this.devices.mics.length > 0;
        const wantVideo = !knownDevices || this.devices.cams.length > 0;
        const wantAudio = !knownDevices || this.devices.mics.length > 0;
        if (!wantVideo) this.status.cam = 'none';
        if (!wantAudio) this.status.mic = 'none';

        if (wantVideo && wantAudio) {
            try {
                const both = await navigator.mediaDevices.getUserMedia({ audio: this.micId ? { ...AUDIO, deviceId: { ideal: this.micId } } : AUDIO, video: this.camId ? { ...VIDEO, deviceId: { ideal: this.camId } } : VIDEO });
                this.adopt(both);
            } catch (e) { /* fall through to the single requests */ }
        }
        if (!this.hasAudio && wantAudio) await this.tryOne('audio');
        if (!this.hasVideo && wantVideo) await this.tryOne('video');

        if (this.hasAudio) this.status.mic = 'ok';
        if (this.hasVideo) this.status.cam = 'ok';
        this.camOn = this.hasVideo;
        this.applyMic();
        await this.refreshDevices();
        this.emit('devices');
        return this.status;
    }

    async tryOne(kind) {
        try {
            const stream = await this.openOne(kind, kind === 'video' ? this.camId : this.micId);
            this.adopt(stream);
        } catch (e) {
            this.status[kind === 'video' ? 'cam' : 'mic'] = classify(e);
        }
    }

    adopt(stream) {
        for (const track of stream.getTracks()) {
            if (track.kind === 'audio') this.setTrack('audio', track);
            else this.setTrack('video', track);
        }
    }

    setTrack(kind, track) {
        const old = kind === 'audio' ? this.audioTrack : this.videoTrack;
        if (old && old !== track) {
            old.onended = null;
            old.stop();
            this.camStream.removeTrack(old);
        }
        if (kind === 'audio') this.audioTrack = track; else this.videoTrack = track;
        if (track) {
            this.camStream.addTrack(track);
            track.onended = () => {
                // The device was unplugged or taken by another app.
                if ((kind === 'audio' ? this.audioTrack : this.videoTrack) === track) {
                    if (kind === 'audio') this.audioTrack = null; else { this.videoTrack = null; this.camOn = false; }
                    this.camStream.removeTrack(track);
                    this.status[kind === 'audio' ? 'mic' : 'cam'] = 'busy';
                    this.emit('track', kind, null);
                    this.emit('lost', kind);
                }
            };
            if (kind === 'audio') track.enabled = this.micOn;
        }
    }

    applyMic() {
        if (this.audioTrack) this.audioTrack.enabled = this.micOn;
    }

    setMic(on) {
        this.micOn = !!on && this.hasAudio;
        this.applyMic();
        this.emit('state');
        return this.micOn;
    }

    /** Camera off releases the device (the light goes out); on opens it again. */
    async setCam(on) {
        if (!on) {
            if (this.videoTrack) {
                const old = this.videoTrack;
                this.videoTrack = null;
                old.onended = null;
                old.stop();
                this.camStream.removeTrack(old);
                this.emit('track', 'video', null);
            }
            this.camOn = false;
            this.emit('state');
            return false;
        }
        if (this.hasVideo) { this.camOn = true; this.emit('state'); return true; }
        try {
            const stream = await this.openOne('video', this.camId);
            const track = stream.getVideoTracks()[0];
            this.setTrack('video', track);
            this.camOn = true;
            this.status.cam = 'ok';
            this.emit('track', 'video', track);
        } catch (e) {
            this.status.cam = classify(e);
            this.camOn = false;
            this.emit('state');
            throw e;
        }
        await this.refreshDevices();
        this.emit('state');
        return true;
    }

    async switchCamera(deviceId) {
        this.camId = deviceId;
        if (!this.camOn && !this.hasVideo) { return; }
        const stream = await this.openOne('video', deviceId);
        const track = stream.getVideoTracks()[0];
        this.setTrack('video', track);
        this.camOn = true;
        this.emit('track', 'video', track);
        await this.refreshDevices();
    }

    async switchMic(deviceId) {
        this.micId = deviceId;
        const stream = await this.openOne('audio', deviceId);
        const track = stream.getAudioTracks()[0];
        this.setTrack('audio', track);
        this.status.mic = 'ok';
        this.emit('track', 'audio', track);
        await this.refreshDevices();
    }

    setSpeaker(deviceId) {
        this.spkId = deviceId;
        this.emit('speaker', deviceId);
    }

    async startScreen() {
        if (!this.canShare) throw Object.assign(new Error('unsupported'), { name: 'NotSupportedError' });
        const stream = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: { ideal: 15, max: 30 } }, audio: false });
        const track = stream.getVideoTracks()[0];
        if (!track) throw Object.assign(new Error('no screen'), { name: 'NotFoundError' });
        try { track.contentHint = 'detail'; } catch (e) { /* optional */ }
        this.screenTrack = track;
        this.screenStream.getTracks().forEach((t) => this.screenStream.removeTrack(t));
        this.screenStream.addTrack(track);
        track.addEventListener('ended', () => {
            if (this.screenTrack === track) this.emit('screen-ended');
        });
        return track;
    }

    stopScreen() {
        const track = this.screenTrack;
        this.screenTrack = null;
        if (track) {
            this.screenStream.removeTrack(track);
            track.stop();
        }
    }

    stopAll() {
        navigator.mediaDevices?.removeEventListener?.('devicechange', this.onDeviceChange);
        for (const track of [this.audioTrack, this.videoTrack, this.screenTrack]) {
            if (track) { track.onended = null; track.stop(); }
        }
        this.audioTrack = this.videoTrack = this.screenTrack = null;
        this.listeners.clear();
    }
}
