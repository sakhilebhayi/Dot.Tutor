// The course player's video: resume at the saved position and save the position when the learner pauses.
// Moved out of the inline script the wave-1 view carried, so a nonce CSP needs nothing extra.
export function resumeAt(saved, duration) {
    const position = Number.parseInt(saved ?? '0', 10);
    if (!Number.isFinite(position) || position <= 0) {
        return null;
    }
    if (Number.isFinite(duration) && position >= duration) {
        return null;
    }

    return position;
}

export function lessonVideo(root) {
    const video = root.getElementById ? root.getElementById('lesson-video') : null;
    if (!video) {
        return;
    }
    video.addEventListener('loadedmetadata', () => {
        const at = resumeAt(video.dataset.resume, video.duration);
        if (at !== null) {
            video.currentTime = at;
        }
    }, { once: true });

    const url = video.dataset.positionUrl;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!url || !token) {
        return;
    }
    video.addEventListener('pause', () => {
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ seconds: Math.floor(video.currentTime) }),
        }).catch(() => {});
    });
}
