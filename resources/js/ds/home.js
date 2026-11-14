// The role homes (R-51): the chart tooltip and the figure reveal. Both are enhancements: the page reads in full
// without them, and reduced motion gets the final numbers at once. Text is set with textContent only.
const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function initChartTips(root) {
    root.querySelectorAll('[data-chart]').forEach((chart) => {
        if (chart.dataset.tipReady) return;
        chart.dataset.tipReady = '1';
        const tip = document.createElement('div');
        tip.className = 'hm-chart__tip';
        tip.hidden = true;
        tip.setAttribute('aria-hidden', 'true');
        chart.appendChild(tip);
        const show = (event) => {
            const hit = event.currentTarget;
            const box = hit.getBoundingClientRect();
            const host = chart.getBoundingClientRect();
            tip.textContent = hit.dataset.tip || '';
            tip.style.left = box.left - host.left + box.width / 2 + 'px';
            tip.style.top = box.top - host.top + 28 + 'px';
            tip.hidden = false;
        };
        const hide = () => { tip.hidden = true; };
        chart.querySelectorAll('.hm-chart__hit').forEach((hit) => {
            hit.addEventListener('pointerenter', show);
            hit.addEventListener('focus', show);
            hit.addEventListener('pointerleave', hide);
            hit.addEventListener('blur', hide);
        });
    });
}

// Whole numbers rise to the true value in 400 ms and always end on it.
function initFigures(root) {
    if (reduced()) return;
    root.querySelectorAll('[data-count]').forEach((el) => {
        if (el.dataset.countReady) return;
        el.dataset.countReady = '1';
        const target = Number.parseInt(el.dataset.count, 10);
        if (!Number.isFinite(target) || target < 2 || target > 100000) return;
        const start = performance.now();
        const duration = 400;
        const step = (now) => {
            const t = Math.min(1, (now - start) / duration);
            el.textContent = String(t >= 1 ? target : Math.round(target * (1 - Math.pow(1 - t, 3))));
            if (t < 1) requestAnimationFrame(step);
        };
        el.textContent = '0';
        requestAnimationFrame(step);
    });
}

export function initHome(root = document) {
    initChartTips(root);
    initFigures(root);
}
