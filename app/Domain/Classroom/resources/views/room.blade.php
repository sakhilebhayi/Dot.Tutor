{{-- The lesson's classroom: who may come in and why not, the pre-join check (camera, microphone, speaker, connection),
     then the call itself, driven by the classroom script. Rendered in the tutor workspace for the tutor and in the
     learner shell for learners and guardians. --}}
@php
    $tz = config('app.timezone');
    $starts = $session->starts_at->copy()->timezone($tz);
    $ends = $session->endsAt()->copy()->timezone($tz);
    $tutorName = $session->tutorProfile?->user?->name ?? 'the tutor';
    $lobbyOpen = $admission->allowed || $admission->code === 'too_early';
    $codeTone = in_array($admission->code, ['too_early', 'not_confirmed'], true) ? 'info' : 'warning';
    $withWhom = $role === 'tutor'
        ? ($session->isGroup() ? count($people) - 1 .' booked '.(count($people) - 1 === 1 ? 'learner' : 'learners') : 'with '.($session->student?->name ?? 'your learner'))
        : 'with '.$tutorName;
@endphp
<x-dynamic-component :component="'layouts::'.$shell" :title="$title">
    <div class="cr" id="classroom-root" data-classroom data-sprite="{{ asset('classroom/icons.svg') }}"
        data-config='@json($config, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)'>

        <header class="cr-head">
            <div class="cr-head__text">
                <h1 class="td-page-head__title">{{ $title }}</h1>
                <p class="cr-head__meta">
                    <time datetime="{{ $session->starts_at->toIso8601String() }}">{{ $starts->format('l j F, H:i') }}</time>
                    to {{ $ends->format('H:i') }} <span class="cr-tz">({{ $tz }})</span>, {{ $withWhom }}
                </p>
            </div>
            <div class="cr-head__aside">
                <span id="cr-status" class="cr-status" role="status" aria-live="polite" data-state="idle">Not joined</span>
                <span id="cr-clock" class="cr-clock" hidden aria-label="Time in the lesson">00:00</span>
                <span id="cr-net" class="cr-net" hidden><x-classroom::icon name="wifi" /><span class="cr-net__word">Measuring</span></span>
            </div>
        </header>

        @unless ($admission->allowed)
            <x-td.notice :tone="$codeTone" :title="$admission->message" id="cr-gate" data-code="{{ $admission->code }}">
                @if ($admission->code === 'too_early')
                    <p>The classroom opens at <time datetime="{{ $admission->opensAt?->toIso8601String() }}">{{ $admission->opensAt?->copy()->timezone($tz)->format('H:i') }}</time>. Test your camera and microphone below while you wait; this page lets you join when it opens.</p>
                @elseif ($admission->code === 'guardian_approval_missing')
                    <p>Live lessons with children need a parent's or guardian's approval for each lesson. The request goes to every linked guardian; they approve it from their guardian page.</p>
                @elseif ($admission->code === 'guardian_consent_required')
                    <p>A learner under 18 needs a linked parent or guardian who has agreed to Dot.Tutor before joining live lessons. Invite one from your safety settings.</p>
                @elseif (in_array($admission->code, ['tutor_screening_missing', 'adult_screening_missing'], true))
                    <p>Adults meet learners under 18 live only after Dot.Tutor has recorded their safety screening. Contact support to have it recorded.</p>
                @elseif (in_array($admission->code, ['lesson_over', 'closed', 'ended'], true))
                    <p><a class="td-link" href="{{ route('classroom.after', $session) }}">Open the lesson record</a> for attendance, the summary and the files.</p>
                @endif
            </x-td.notice>
        @endunless

        @if ($approvals !== [])
            <section class="cr-approvals" aria-labelledby="cr-approvals-h">
                <h2 class="cr-h2" id="cr-approvals-h">Guardian approval for this live lesson</h2>
                <ul class="cr-list">
                    @foreach ($approvals as $row)
                        <li class="cr-list__row">
                            <span>{{ $row['name'] }}</span>
                            <span class="cr-tag" data-tone="{{ $row['status'] === 'approved' ? 'ok' : (in_array($row['status'], ['declined', 'expired'], true) ? 'bad' : 'wait') }}">
                                {{ ['approved' => 'Approved', 'pending' => 'Waiting for the guardian', 'declined' => 'Declined by the guardian', 'expired' => 'Request expired', 'cancelled' => 'Request cancelled', 'none' => 'Not asked yet', 'not_required' => 'Not needed'][$row['status']] ?? $row['status'] }}
                            </span>
                        </li>
                    @endforeach
                </ul>
                @if (in_array($role, ['learner', 'tutor'], true) && collect($approvals)->contains(fn ($r) => in_array($r['status'], ['none', 'expired', 'declined', 'cancelled'], true)))
                    <form method="POST" action="{{ route('classroom.approval', $session) }}">
                        @csrf
                        <x-td.button type="submit" variant="primary" icon="hand-heart">{{ $role === 'learner' ? 'Ask my parent or guardian to approve' : 'Ask the guardians to approve' }}</x-td.button>
                    </form>
                @endif
            </section>
        @endif

        @if ($lobbyOpen)
            {{-- 1. Pre-join check: nobody sees or hears you until you press Join. --}}
            <section id="cr-lobby" class="cr-lobby" aria-labelledby="cr-lobby-h">
                <div class="cr-lobby__preview">
                    <video id="cr-lobby-video" autoplay muted playsinline aria-label="Your camera preview, only you see this"></video>
                    <div id="cr-lobby-nocam" class="cr-nocam" hidden><x-classroom::icon name="video-off" /><span id="cr-lobby-nocam-text">Camera is off</span></div>
                    <div class="cr-lobby__toggles">
                        <button type="button" id="cr-lobby-mic" class="cr-ctl" aria-pressed="true" aria-label="Microphone on"><x-classroom::icon name="mic" /></button>
                        <button type="button" id="cr-lobby-cam" class="cr-ctl" aria-pressed="true" aria-label="Camera on"><x-classroom::icon name="video" /></button>
                    </div>
                </div>
                <div class="cr-lobby__form">
                    <h2 class="cr-h2" id="cr-lobby-h">Check your camera and microphone</h2>
                    <p class="cr-muted">Only you see this preview. Nobody sees or hears you until you join.</p>
                    <div class="td-field"><label class="td-label" for="cr-sel-cam">Camera</label><span class="td-select-wrap"><select class="td-select" id="cr-sel-cam"><option>Looking for cameras</option></select></span></div>
                    <div class="td-field">
                        <label class="td-label" for="cr-sel-mic">Microphone</label>
                        <span class="td-select-wrap"><select class="td-select" id="cr-sel-mic"><option>Looking for microphones</option></select></span>
                        <div class="cr-level" role="meter" aria-label="Microphone level" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="cr-level"><i id="cr-level-fill"></i></div>
                        <p class="td-hint" id="cr-level-hint">Say something: the bar moves when your microphone hears you.</p>
                    </div>
                    <div class="td-field" id="cr-spk-wrap" hidden><label class="td-label" for="cr-sel-spk">Speaker</label><span class="td-select-wrap"><select class="td-select" id="cr-sel-spk"></select></span></div>
                    <dl class="cr-checks" id="cr-checks" aria-label="Checks before you join">
                        <div><dt>Browser</dt><dd id="cr-check-browser">Checking</dd></div>
                        <div><dt>Camera</dt><dd id="cr-check-cam">Checking</dd></div>
                        <div><dt>Microphone</dt><dd id="cr-check-mic">Checking</dd></div>
                        <div><dt>Connection</dt><dd>{{ $relay ? 'Direct, with a relay server when needed.' : 'Direct only. No relay server is set up, so some school and office networks cannot connect.' }}</dd></div>
                    </dl>
                    <p id="cr-lobby-msg" class="cr-lobby__msg" role="status"></p>
                    <div class="cr-actions">
                        <x-td.button id="cr-join" variant="primary" size="lg" icon="video" :disabled="! $admission->allowed" data-opens="{{ $admission->opensAt?->toIso8601String() }}">
                            {{ $role === 'guardian' ? 'Join to watch' : 'Join the lesson' }}
                        </x-td.button>
                    </div>
                    <noscript><p class="cr-muted">The classroom needs JavaScript and a recent browser (Chrome, Edge, Firefox or Safari).</p></noscript>
                </div>
            </section>

            {{-- 2. The call. --}}
            <section id="cr-live" class="cr-live" hidden aria-label="The lesson">
                <div id="cr-banner" class="cr-banner" role="status" aria-live="polite" hidden></div>
                <div class="cr-layout">
                    <div class="cr-main">
                        <x-td.tabs label="Lesson view" :items="[['label' => 'Video', 'panel' => 'cr-tab-video'], ['label' => 'Whiteboard', 'panel' => 'cr-tab-board']]" />
                        <x-td.tab-panel id="cr-tab-video" class="cr-panel">
                            <div id="cr-grid" class="cr-grid" data-count="1"></div>
                        </x-td.tab-panel>
                        <x-td.tab-panel id="cr-tab-board" class="cr-panel" hidden>
                            <div id="cr-strip" class="cr-strip" aria-label="People in the lesson"></div>
                            <div class="cr-board">
                                <div class="cr-board__tools" role="toolbar" aria-label="Whiteboard tools">
                                    <div class="cr-seg" role="radiogroup" aria-label="Tool">
                                        @foreach (['pen' => ['pen-line', 'Pen'], 'erase' => ['eraser', 'Eraser'], 'line' => ['minus', 'Line'], 'rect' => ['square', 'Rectangle'], 'ellipse' => ['circle', 'Ellipse'], 'text' => ['type', 'Text']] as $tool => [$glyph, $label])
                                            <button type="button" class="cr-tool" role="radio" data-tool="{{ $tool }}" aria-checked="{{ $tool === 'pen' ? 'true' : 'false' }}" aria-label="{{ $label }}" title="{{ $label }}"><x-classroom::icon :name="$glyph" /></button>
                                        @endforeach
                                    </div>
                                    <div class="cr-seg" role="radiogroup" aria-label="Colour">
                                        @foreach (['ink' => 'Ink', 'primary' => 'Green', 'danger' => 'Red', 'info' => 'Blue', 'warning' => 'Amber'] as $colour => $label)
                                            <button type="button" class="cr-swatch" role="radio" data-colour="{{ $colour }}" aria-checked="{{ $colour === 'ink' ? 'true' : 'false' }}" aria-label="{{ $label }}" title="{{ $label }}"><i data-c="{{ $colour }}"></i></button>
                                        @endforeach
                                    </div>
                                    <label class="cr-width"><span>Size</span><input type="range" id="cr-width" min="1" max="24" value="4"></label>
                                    <div class="cr-pages">
                                        <button type="button" class="cr-ctl cr-ctl--sm" id="cr-page-prev" aria-label="Previous page"><x-classroom::icon name="chevron-left" /></button>
                                        <span id="cr-page-label" aria-live="polite">Page 1 of 1</span>
                                        <button type="button" class="cr-ctl cr-ctl--sm" id="cr-page-next" aria-label="Next page"><x-classroom::icon name="chevron-right" /></button>
                                        <button type="button" class="cr-ctl cr-ctl--sm" id="cr-page-add" aria-label="Add a page" title="Add a page"><x-classroom::icon name="plus" /></button>
                                        <button type="button" class="cr-ctl cr-ctl--sm" id="cr-page-clear" aria-label="Clear this page" title="Clear this page"><x-classroom::icon name="trash-2" /></button>
                                        <button type="button" class="cr-ctl cr-ctl--sm" id="cr-page-save" aria-label="Save this page to the lesson files" title="Save this page to the lesson files"><x-classroom::icon name="image-down" /></button>
                                    </div>
                                </div>
                                <div class="cr-board__sheet" id="cr-sheet">
                                    <canvas id="cr-canvas" role="img" aria-label="Shared whiteboard" aria-describedby="cr-board-text"></canvas>
                                    <form id="cr-text-form" class="cr-text-form" hidden>
                                        <label class="td-sr" for="cr-text-input">Text to place on the whiteboard</label>
                                        <input id="cr-text-input" class="td-input" maxlength="{{ config('classroom.board.max_text') }}" autocomplete="off" placeholder="Type, then press Enter">
                                    </form>
                                </div>
                                <p id="cr-board-text" class="cr-muted cr-board__alt">Nothing on this page yet.</p>
                            </div>
                        </x-td.tab-panel>
                    </div>

                    <aside class="cr-side" aria-label="Lesson panel">
                        <x-td.tabs label="Lesson panel" :items="[['label' => 'Chat', 'panel' => 'cr-tab-chat'], ['label' => 'Notes', 'panel' => 'cr-tab-notes'], ['label' => 'Files', 'panel' => 'cr-tab-files'], ['label' => 'People', 'panel' => 'cr-tab-people']]" />
                        <x-td.tab-panel id="cr-tab-chat" class="cr-panel cr-chat">
                            <ol id="cr-chat-log" class="cr-chat__log" aria-label="Chat messages" aria-live="polite"></ol>
                            <p class="cr-muted cr-chat__note" id="cr-chat-note">Messages go to everyone in this lesson and are kept with it. @if ($minorInLesson)Phone numbers, emails and social handles are hidden because a learner is under 18.@endif</p>
                            <form id="cr-chat-form" class="cr-chat__form">
                                <label class="td-sr" for="cr-chat-input">Message to the lesson</label>
                                <textarea id="cr-chat-input" class="td-textarea" rows="2" maxlength="{{ config('classroom.chat.max_length') }}" placeholder="Write a message"></textarea>
                                <x-td.button type="submit" variant="primary">Send</x-td.button>
                            </form>
                        </x-td.tab-panel>
                        <x-td.tab-panel id="cr-tab-notes" class="cr-panel" hidden>
                            <label class="td-label" for="cr-notes">Shared notes</label>
                            <p class="td-hint" id="cr-notes-hint">Everyone in the lesson can write here. Saved a moment after you stop typing, and kept with the lesson.</p>
                            <textarea id="cr-notes" class="td-textarea cr-notes" rows="14" aria-describedby="cr-notes-hint cr-notes-state"></textarea>
                            <p id="cr-notes-state" class="cr-muted" role="status"></p>
                            <div id="cr-notes-conflict" class="cr-conflict" hidden>
                                <p><strong>Someone else saved the notes while you were typing.</strong> Their version is in the box above. Your text is below: copy what you need back in.</p>
                                <textarea id="cr-notes-mine" class="td-textarea" rows="5" readonly aria-label="Your unsaved text"></textarea>
                            </div>
                        </x-td.tab-panel>
                        <x-td.tab-panel id="cr-tab-files" class="cr-panel" hidden>
                            <ul id="cr-files" class="cr-list" aria-label="Lesson files"></ul>
                            <form id="cr-upload" class="cr-upload">
                                <div class="td-field"><label class="td-label" for="cr-file">Share a file</label><input id="cr-file" name="file" type="file" class="td-input" accept=".pdf,.png,.jpg,.jpeg,.txt,application/pdf,image/png,image/jpeg,text/plain" aria-describedby="cr-file-hint"><p class="td-hint" id="cr-file-hint">PDF, PNG, JPEG or plain text, up to {{ round(config('classroom.files.max_kb') / 1024) }} MB. Files stay with the lesson for everyone in it. Files are not virus-scanned.</p></div>
                                <div class="td-field"><label class="td-label" for="cr-file-title">Title <span class="td-label__optional">optional</span></label><input id="cr-file-title" class="td-input" maxlength="200"></div>
                                <x-td.button type="submit" icon="upload">Share the file</x-td.button>
                                <p id="cr-upload-msg" class="cr-muted" role="status"></p>
                            </form>
                        </x-td.tab-panel>
                        <x-td.tab-panel id="cr-tab-people" class="cr-panel" hidden>
                            <ul id="cr-people" class="cr-list" aria-label="People in the lesson"></ul>
                        </x-td.tab-panel>
                    </aside>
                </div>

                <div id="cr-controls" class="cr-controls" role="toolbar" aria-label="Lesson controls">
                    <button type="button" id="cr-mic" class="cr-ctl" aria-pressed="true" aria-label="Microphone on" title="Mute (m)"><x-classroom::icon name="mic" /></button>
                    <button type="button" id="cr-cam" class="cr-ctl" aria-pressed="true" aria-label="Camera on" title="Turn camera off (v)"><x-classroom::icon name="video" /></button>
                    <button type="button" id="cr-share" class="cr-ctl" aria-pressed="false" aria-label="Share your screen" title="Share your screen"><x-classroom::icon name="monitor-up" /></button>
                    <details class="cr-menu" id="cr-devices">
                        <summary class="cr-ctl" aria-label="Camera, microphone and speaker" title="Devices"><x-classroom::icon name="sliders-horizontal" /></summary>
                        <div class="cr-menu__panel" id="cr-devices-panel"></div>
                    </details>
                    <span class="cr-controls__gap"></span>
                    <button type="button" id="cr-leave" class="cr-ctl cr-ctl--leave" aria-label="Leave the lesson" title="Leave"><x-classroom::icon name="phone-off" /></button>
                    @if ($role === 'tutor')
                        <x-td.button id="cr-end" variant="danger" size="sm">End the lesson</x-td.button>
                    @endif
                </div>
            </section>

            <section id="cr-notice" class="cr-notice" hidden aria-live="assertive"></section>
        @endif

        {{-- Around the lesson: who is booked, the fallback link, recording and safety. --}}
        <div class="cr-about">
            <section aria-labelledby="cr-people-h">
                <h2 class="cr-h2" id="cr-people-h">Booked into this lesson</h2>
                <ul class="cr-list">
                    @foreach ($people as $person)
                        <li class="cr-list__row"><span>{{ $person['name'] }}</span><span class="cr-muted">{{ $person['role'] }}</span></li>
                    @endforeach
                </ul>
                <p class="cr-muted">The in-browser classroom holds {{ config('classroom.max_in_room') }} people at once: each browser sends its video straight to every other one.</p>
            </section>

            <section aria-labelledby="cr-fallback-h">
                <h2 class="cr-h2" id="cr-fallback-h">If the classroom cannot connect</h2>
                <p>Browsers connect to each other directly. No relay server is set up yet, so a few networks (some schools, offices and mobile carriers) block the connection. When that happens the room says so and offers {{ $role === 'tutor' ? 'your' : "the tutor's" }} own meeting link.</p>
                @if ($role === 'tutor')
                    <form method="POST" action="{{ route('classroom.link', $session) }}" class="cr-inline-form">
                        @csrf
                        <x-td.field label="Your meeting link (Google Meet, Teams, Zoom or similar)" for="external_url" hint="An https link. Learners see it only inside this lesson's classroom." optional :error="$errors->first('external_url')">
                            <x-td.input name="external_url" type="url" :value="old('external_url', $classroom->external_url)" placeholder="https://" :hint="true" />
                        </x-td.field>
                        <x-td.button type="submit">Save the link</x-td.button>
                    </form>
                @elseif ($classroom->external_url)
                    <p><a class="td-link" href="{{ $classroom->external_url }}" rel="noopener noreferrer" target="_blank">Open the tutor's meeting link<x-classroom::icon name="external-link" /></a> (opens outside Dot.Tutor).</p>
                @else
                    <p class="cr-muted">Your tutor has not added a meeting link.</p>
                @endif
            </section>

            <section aria-labelledby="cr-privacy-h">
                <h2 class="cr-h2" id="cr-privacy-h">Recording and privacy</h2>
                <p><strong>This lesson is not recorded.</strong> Dot.Tutor does not record lessons: the host cannot store video safely yet. Audio and video go straight between the browsers in the lesson, encrypted, and never through Dot.Tutor's server.</p>
                @if ($stun)
                    <p class="cr-muted">To find a direct path between browsers, yours asks a STUN server ({{ implode(', ', array_map(fn ($u) => preg_replace('/^stuns?:/', '', $u), config('classroom.rtc.stun_urls'))) }}) for your public internet address. It sees that address only, never your video, voice, name or account.</p>
                @endif
                <p class="cr-muted">Chat, shared notes, whiteboard and files are kept with the lesson for the people in it. Anything that worries you can be reported from the chat, or to Dot.Tutor support.</p>
            </section>
        </div>
    </div>
</x-dynamic-component>
