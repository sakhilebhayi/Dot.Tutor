// R-47 learner screens (student UI agent). Small progressive enhancements; every page works without them.
import { autosubmit } from './autosubmit.js';
import { lessonVideo } from './player.js';

function boot() {
    autosubmit(document);
    lessonVideo(document);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
