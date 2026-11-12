Dot.Tutor classroom: two-browser check
======================================

What it drives: two real Google Chrome contexts (a tutor at 1440 x 1000, a learner on a 390 x 844 phone viewport) against
the running app, with Chrome's fake camera and microphone (--use-fake-device-for-media-stream, --use-fake-ui-for-media-stream).
Evidence comes from RTCPeerConnection.getStats(), read through an init script that wraps RTCPeerConnection, independently
of the app code.

Setup (only here, not in the app's package.json):  cd tests/e2e/classroom && npm install
Run:   php artisan db:seed --class=DemoClassroomSeeder   (local only; refuses production; resets the demo lesson)
       php artisan serve --host=127.0.0.1 --port=8707 (with assets built: npm run build)
       node tests/e2e/classroom/run.mjs                 (BASE_URL, LESSON, HEADED=1 to watch)

It checks: the pre-join meter moves; both peers reach "connected"; video frames decoded rise both ways; audio bytes and
energy rise both ways; a nominated candidate pair; the remote tile plays; mute reaches the other side; chat; whiteboard
stroke and text (and painted pixels on the other canvas); shared notes; no horizontal scroll and 44 px controls at 390 px;
the m shortcut; the tutor ending the lesson (the learner told, peer connections closed); attendance on the record; no
script errors. Screenshots go to ./shots.

Honest limits: both browsers run on one machine, so the path is a host candidate; no TURN server exists, so a relay is not
tested; Chrome only (no Safari, Firefox or real phones); screen sharing is not exercised here.

Folded into the browser end-to-end suite (R-47 QA): tests/e2e/specs/08-classroom-webrtc.spec.mjs runs the same checks inside
node tests/e2e/run.mjs (throwaway app, no seeder, no STUN). This standalone script still works against a dev server.
