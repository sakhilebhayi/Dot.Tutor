// File picker: lists what was chosen (name and size) and flags files over the stated size before upload.
// The server validates again; this only saves a wasted upload on a slow connection.
export function formatBytes(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function initFilePickers(root = document) {
    root.querySelectorAll('[data-td-file]:not([data-td-ready])').forEach((zone) => {
        zone.dataset.tdReady = '1';
        const input = zone.querySelector('input[type="file"]');
        const list = zone.querySelector('[data-td-file-list]');
        const maxBytes = Number(zone.dataset.tdMaxBytes || 0);
        if (!input || !list) {
            return;
        }

        ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, () => zone.classList.add('is-dragging')));
        ['dragleave', 'drop'].forEach((type) => zone.addEventListener(type, () => zone.classList.remove('is-dragging')));

        input.addEventListener('change', () => {
            list.replaceChildren();
            let tooBig = false;
            Array.from(input.files || []).forEach((file) => {
                const item = document.createElement('li');
                const over = maxBytes > 0 && file.size > maxBytes;
                tooBig = tooBig || over;
                item.textContent = `${file.name}, ${formatBytes(file.size)}${over ? ' (too large)' : ''}`;
                if (over) {
                    item.style.color = 'var(--td-danger-ink)';
                }
                list.append(item);
            });
            input.setCustomValidity(tooBig ? `Each file must be ${formatBytes(maxBytes)} or smaller.` : '');
            if (tooBig) {
                input.reportValidity();
            }
        });
    });
}
