// A deadline that counts down while the page is open: <time datetime="ISO" data-countdown data-countdown-before="Acknowledge within"
// data-countdown-after="Overdue by">server text</time>. The server writes the same words, so without script the page is
// right when it loads (R-47 fix D-2405, the safeguarding incident's response timer).
export function spanWords(minutes) {
    return minutes >= 120 ? `${Math.floor(minutes / 60)} h ${minutes % 60} min` : `${minutes} min`;
}

export function countdownText(deadlineMs, nowMs, before, after) {
    // Nearest minute, the same rounding as the server's text (resources/views/ops/safeguarding/incident.blade.php).
    const seconds = (deadlineMs - nowMs) / 1000;
    const minutes = Math.sign(seconds) * Math.round(Math.abs(seconds) / 60);
    return minutes >= 0 ? `${before} ${spanWords(minutes)}` : `${after} ${spanWords(-minutes)}`;
}

export function initCountdowns(root = document) {
    const nodes = [...root.querySelectorAll('time[data-countdown]')];
    if (nodes.length === 0) {
        return;
    }
    const tick = () => {
        for (const node of nodes) {
            const deadline = Date.parse(node.getAttribute('datetime') || '');
            if (Number.isNaN(deadline)) {
                continue;
            }
            const text = countdownText(deadline, Date.now(), node.dataset.countdownBefore || 'Due in', node.dataset.countdownAfter || 'Overdue by');
            if (node.textContent !== text) {
                node.textContent = text;
            }
        }
    };
    tick();
    window.setInterval(tick, 15000);
}
