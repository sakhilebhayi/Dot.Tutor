// Adapted from Dot.Engage (resources/js/meeting/announce.js, R-39).
// Screen reader announcements: one polite live region, throttled so a burst of joins is read as one sentence.

export class Announcer {
    constructor() {
        this.region = document.createElement('div');
        this.region.className = "td-sr";
        this.region.setAttribute('aria-live', 'polite');
        this.region.setAttribute('aria-atomic', 'true');
        this.region.id = 'sr-announcer';
        document.body.append(this.region);
        this.queue = [];
        this.timer = null;
        this.last = 0;
    }

    say(message) {
        if (!message || this.queue.includes(message)) return;
        this.queue.push(message);
        if (this.timer) return;
        const wait = Math.max(0, 1500 - (Date.now() - this.last));
        this.timer = setTimeout(() => this.flush(), wait);
    }

    flush() {
        this.timer = null;
        if (!this.queue.length) return;
        this.region.textContent = this.queue.join('. ') + '.';
        this.queue = [];
        this.last = Date.now();
    }

    destroy() {
        clearTimeout(this.timer);
        this.timer = null;
        this.region.remove();
    }
}
