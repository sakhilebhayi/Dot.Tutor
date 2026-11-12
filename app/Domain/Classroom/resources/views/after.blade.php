{{-- After the lesson: attendance from real joins, the summary, files, and for the tutor their private notes, follow-up
     work and completing the lesson through the marketplace. --}}
@php
    $tz = $session->tutorProfile?->timezone ?: config('app.timezone');
    $starts = $session->starts_at->copy()->timezone($tz);
    $fmt = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone($tz)->format('H:i') : null;
    $published = $classroom->summary_published_at !== null;
@endphp
<x-dynamic-component :component="'layouts::'.$shell" :title="'After: '.$title">
    <x-td.page-head :title="$title" :lede="$starts->format('l j F Y, H:i').' ('.$tz.'). Lesson status: '.$statusLabel.'.'">
        <x-slot:actions>
            @if (in_array($session->statusEnum()->value, ['confirmed', 'in_progress'], true) && ($role === 'tutor' || ! $classroom->isEnded()))
                <x-td.button :href="route('classroom.show', $session)" icon="video">Back to the classroom</x-td.button>
            @endif
        </x-slot:actions>
    </x-td.page-head>

    @if ($errors->any())
        <x-td.notice tone="danger" title="That did not work">{{ $errors->first() }}</x-td.notice>
    @endif

    <div class="cr-after">
        <div class="cr-after__main">
            <x-td.panel title="Summary">
                @if ($role === 'tutor')
                    <form method="POST" action="{{ route('classroom.summary', $session) }}" class="cr-form">
                        @csrf
                        @if (old('ai_draft'))
                            <x-td.notice tone="info" title="AI draft">Written by an AI from the lesson's notes and chat. Check every line before you publish; it is published under your name.</x-td.notice>
                        @endif
                        <x-td.field label="What you covered, what went well, what to practise next" for="summary" :hint="$published ? 'Published: the learners and their guardians can read it. Saving updates it.' : 'Learners see it only once you publish.'">
                            <x-td.textarea name="summary" rows="8" :value="old('summary', $classroom->summary)" maxlength="8000" :hint="true" />
                        </x-td.field>
                        <div class="cr-actions">
                            <x-td.button type="submit" name="publish" value="1" variant="primary">{{ $published ? 'Save the summary' : 'Publish to the learners' }}</x-td.button>
                            @unless ($published)
                                <x-td.button type="submit" name="publish" value="0">Save as a draft</x-td.button>
                            @endunless
                        </div>
                    </form>
                    <form method="POST" action="{{ route('classroom.summary.draft', $session) }}" class="cr-form cr-form--quiet">
                        @csrf
                        <x-td.button type="submit" variant="quiet" size="sm" :disabled="! $drafterAvailable">Draft it with AI</x-td.button>
                        @unless ($drafterAvailable)<span class="cr-muted">No AI drafting is connected to the classroom yet.</span>@endunless
                    </form>
                @elseif ($published)
                    <div class="cr-summary">{!! nl2br(e($classroom->summary)) !!}</div>
                    <p class="cr-muted">Written by {{ $session->tutorProfile?->user?->name ?? 'the tutor' }}.</p>
                @else
                    <x-td.state kind="empty" title="No summary yet">The tutor writes a summary after the lesson. It appears here.</x-td.state>
                @endif
            </x-td.panel>

            <x-td.panel title="Attendance" flush>
                <x-td.table caption="Who was in the classroom, from real joins" caption-hidden stack>
                    <thead><tr><th>Person</th><th>Role</th><th>First joined</th><th>Last left</th><th class="is-num" data-stack="key">Minutes</th><th class="is-num">Joins</th></tr></thead>
                    <tbody>
                        @foreach ($attendance as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ ucfirst($row['role']) }}</td>
                                <td>{{ $fmt($row['first_joined']) ?? 'Did not join' }}</td>
                                <td>{{ $row['open'] ? 'Still in the room' : ($fmt($row['last_left']) ?? '') }}</td>
                                <td class="is-num">{{ $row['minutes'] }}</td>
                                <td class="is-num">{{ $row['joins'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                <p class="cr-muted cr-pad">Counted from the moment each person joined until they left, or until their browser last answered if it dropped. Times in {{ $tz }}.</p>
            </x-td.panel>
        </div>

        <div class="cr-after__side">
            @if ($role === 'tutor')
                <x-td.panel title="Complete the lesson">
                    @if ($canComplete)
                        <p>Marking it completed counts the lesson, releases your earning on the money side, and lets the learners review it.</p>
                        <form method="POST" action="{{ route('classroom.complete', $session) }}">
                            @csrf
                            <x-td.button type="submit" variant="primary" icon="circle-check">Mark the lesson completed</x-td.button>
                        </form>
                    @else
                        <p class="cr-muted">{{ $session->statusEnum()->value === 'completed' ? 'This lesson is completed.' : 'A lesson can be marked completed from its start time.' }}</p>
                    @endif
                </x-td.panel>

                <x-td.panel title="Your lesson notes">
                    <form method="POST" action="{{ route('classroom.notes', $session) }}" class="cr-form">
                        @csrf
                        <x-td.field label="Private notes" for="tutor_notes" hint="Only you see these.">
                            <x-td.textarea name="tutor_notes" rows="5" :value="old('tutor_notes', $classroom->tutor_notes)" :hint="true" />
                        </x-td.field>
                        <x-td.button type="submit">Save my notes</x-td.button>
                    </form>
                </x-td.panel>

                <x-td.panel title="Follow-up work">
                    @if ($assignable === [] || $learners->isEmpty())
                        <x-td.state kind="empty" title="Nothing to assign yet">Create a tutor assessment with questions in Practice, then assign it here.</x-td.state>
                    @else
                        <form method="POST" action="{{ route('classroom.follow-up', $session) }}" class="cr-form">
                            @csrf
                            <x-td.field label="Assessment" for="assessment_id"><x-td.select name="assessment_id" :options="collect($assignable)->pluck('title', 'id')->all()" /></x-td.field>
                            <x-td.field label="Learner" for="learner_id"><x-td.select name="learner_id" :options="$learners->pluck('name', 'id')->all()" /></x-td.field>
                            <x-td.field label="Due" for="due_at" optional><x-td.input name="due_at" type="datetime-local" /></x-td.field>
                            <x-td.button type="submit">Assign</x-td.button>
                        </form>
                    @endif
                </x-td.panel>
            @endif

            <x-td.panel title="Lesson files">
                @if ($files === [])
                    <x-td.state kind="empty" title="No files">Files shared in the classroom and saved whiteboard pages appear here.</x-td.state>
                @else
                    <ul class="cr-list">
                        @foreach ($files as $file)
                            <li class="cr-list__row"><a class="td-link" href="{{ $file['url'] }}">{{ $file['title'] }}</a><span class="cr-muted">{{ strtoupper($file['type']) }}{{ $file['by'] ? ', '.$file['by'] : '' }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>

            @if ($classroom->shared_notes)
                <x-td.panel title="Shared notes">
                    <div class="cr-summary">{!! nl2br(e($classroom->shared_notes)) !!}</div>
                </x-td.panel>
            @endif
        </div>
    </div>
</x-dynamic-component>
