// The pre-join check (adapted from Dot.Engage's lobby): camera preview, device choice, a live microphone meter and
// plain checks. Nothing is sent anywhere from here: the camera and microphone stay on this device until Join.

import { $, h, swapIcon } from './util.js';
import { LevelMeter } from './audio.js';

const CAM_WORDS = {
    ok: 'Working', denied: 'Blocked in the browser. Allow the camera in the address bar, then reload.', none: 'No camera found. You can still join with sound only.',
    busy: 'In use by another app. Close it and reload.', unsupported: 'This browser cannot use a camera.', error: 'Could not be opened.', pending: 'Checking',
};
const MIC_WORDS = {
    ok: 'Working', denied: 'Blocked in the browser. Allow the microphone in the address bar, then reload.', none: 'No microphone found. You can still listen.',
    busy: 'In use by another app. Close it and reload.', unsupported: 'This browser cannot use a microphone.', error: 'Could not be opened.', pending: 'Checking',
};

export class Lobby {
    constructor({ media, announcer }) {
        this.media = media;
        this.announcer = announcer;
        this.meter = null;
        this.closed = false;
        this.el = {
            video: $('cr-lobby-video'), nocam: $('cr-lobby-nocam'), nocamText: $('cr-lobby-nocam-text'),
            mic: $('cr-lobby-mic'), cam: $('cr-lobby-cam'),
            selCam: $('cr-sel-cam'), selMic: $('cr-sel-mic'), selSpk: $('cr-sel-spk'), spkWrap: $('cr-spk-wrap'),
            level: $('cr-level'), fill: $('cr-level-fill'), msg: $('cr-lobby-msg'),
            browser: $('cr-check-browser'), checkCam: $('cr-check-cam'), checkMic: $('cr-check-mic'),
        };
        this.off = [];
        const on = (node, name, fn) => { node.addEventListener(name, fn); this.off.push(() => node.removeEventListener(name, fn)); };
        const e = this.el;
        on(e.mic, 'click', () => { this.media.setMic(!this.media.micOn); this.render(); });
        on(e.cam, 'click', async () => {
            e.cam.disabled = true;
            try { await this.media.setCam(!this.media.camOn); } catch (err) { this.announcer.say('The camera could not be turned on'); }
            this.render();
        });
        on(e.selCam, 'change', async () => {
            try { await this.media.switchCamera(e.selCam.value); } catch (err) { this.announcer.say('That camera could not be opened'); }
            this.render();
        });
        on(e.selMic, 'change', async () => {
            try { await this.media.switchMic(e.selMic.value); } catch (err) { this.announcer.say('That microphone could not be opened'); }
            this.render();
            this.startMeter();
        });
        on(e.selSpk, 'change', () => this.media.setSpeaker(e.selSpk.value));
        this.unlisten = this.media.on('devices', () => this.render());
    }

    async start() {
        const ok = typeof RTCPeerConnection !== 'undefined' && this.media.supported;
        this.el.browser.textContent = ok ? 'Ready for live lessons' : 'This browser cannot make video calls. Use a recent Chrome, Edge, Firefox or Safari.';
        if (!ok) return false;
        this.el.msg.textContent = 'Looking for your camera and microphone. Your browser may ask for permission.';
        await this.media.acquire();
        if (this.closed) return true;
        this.el.msg.textContent = '';
        this.render();
        this.startMeter();
        return true;
    }

    startMeter() {
        this.meter?.stop();
        this.meter = null;
        if (this.closed || !this.media.hasAudio) { this.paintLevel(0); return; }
        this.meter = new LevelMeter(new MediaStream([this.media.audioTrack]), { ownContext: true, onLevel: (level) => this.paintLevel(level) });
    }

    paintLevel(level) {
        this.el.fill.style.transform = `scaleX(${level.toFixed(3)})`;
        this.el.level.setAttribute('aria-valuenow', String(Math.round(level * 100)));
        this.el.level.dataset.active = level > 0.05 ? 'true' : 'false';
    }

    render() {
        if (this.closed) return;
        const m = this.media;
        const e = this.el;
        const s = m.status;

        const preview = m.camOn ? m.previewStream() : null;
        if (preview) {
            const current = e.video.srcObject && e.video.srcObject.getVideoTracks()[0];
            if (current !== m.videoTrack) { e.video.srcObject = preview; e.video.play().catch(() => {}); }
        } else {
            e.video.srcObject = null;
        }
        e.video.hidden = !preview;
        e.nocam.hidden = !!preview;
        e.nocamText.textContent = s.cam === 'ok' || m.hasVideo ? 'Camera is off' : (CAM_WORDS[s.cam] || 'No camera').split('.')[0];

        e.mic.disabled = !m.hasAudio;
        e.mic.setAttribute('aria-pressed', m.hasAudio && m.micOn ? 'true' : 'false');
        e.mic.setAttribute('aria-label', !m.hasAudio ? 'Microphone not available' : m.micOn ? 'Microphone on' : 'Microphone off');
        swapIcon(e.mic, m.hasAudio && m.micOn ? 'mic' : 'mic-off');
        const camPossible = s.cam === 'ok' || m.hasVideo;
        e.cam.disabled = !camPossible;
        e.cam.setAttribute('aria-pressed', m.camOn && m.hasVideo ? 'true' : 'false');
        e.cam.setAttribute('aria-label', !camPossible ? 'Camera not available' : m.camOn ? 'Camera on' : 'Camera off');
        swapIcon(e.cam, m.camOn && m.hasVideo ? 'video' : 'video-off');

        this.fill(e.selCam, m.devices.cams, m.camId, s.cam === 'denied' ? 'Camera is blocked' : 'No camera found', 'Camera');
        this.fill(e.selMic, m.devices.mics, m.micId, s.mic === 'denied' ? 'Microphone is blocked' : 'No microphone found', 'Microphone');
        if (m.canPickSpeaker && m.devices.spks.length) {
            e.spkWrap.hidden = false;
            this.fill(e.selSpk, [{ deviceId: '', label: 'Default speaker' }, ...m.devices.spks.filter((d) => d.deviceId !== 'default')], m.spkId, 'Default speaker', 'Speaker');
        }

        e.checkCam.textContent = m.hasVideo && !m.camOn ? 'Working, turned off' : CAM_WORDS[s.cam] || s.cam;
        e.checkMic.textContent = m.hasAudio && !m.micOn ? 'Working, muted' : MIC_WORDS[s.mic] || s.mic;
        e.checkCam.dataset.tone = s.cam === 'ok' ? 'ok' : s.cam === 'pending' ? '' : 'warn';
        e.checkMic.dataset.tone = s.mic === 'ok' ? 'ok' : s.mic === 'pending' ? '' : 'warn';
    }

    fill(select, devices, selected, emptyText, label) {
        const focused = document.activeElement === select;
        const options = devices.length
            ? devices.map((d, i) => h('option', { value: d.deviceId }, d.label || `${label} ${i + 1}`))
            : [h('option', { value: '' }, emptyText)];
        select.replaceChildren(...options);
        select.disabled = !devices.length;
        if (selected) select.value = selected;
        if (focused) select.focus();
    }

    stop() {
        this.closed = true;
        this.meter?.stop();
        this.meter = null;
        this.off.forEach((fn) => fn());
        this.off = [];
        this.unlisten?.();
        this.el.video.srcObject = null;
    }
}
