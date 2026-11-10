// Design-system behaviour. Plain modules, no dependencies, no CDN. Each one enhances markup that already works
// without script (links, native dialogs, real file inputs); see docs/R47_DS.md for the markup each one expects.
import { initTheme } from './theme.js';
import { initTabs } from './tabs.js';
import { initDialogs } from './dialog.js';
import { initToasts } from './toast.js';
import { initComboboxes } from './combobox.js';
import { initFilePickers } from './file-picker.js';

function boot(root = document) {
    initTheme(root);
    initTabs(root);
    initDialogs(root);
    initToasts(root);
    initComboboxes(root);
    initFilePickers(root);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => boot());
} else {
    boot();
}

// Livewire swaps parts of the page; enhance whatever it adds.
document.addEventListener('livewire:navigated', () => boot());

window.TutorDs = { boot };
