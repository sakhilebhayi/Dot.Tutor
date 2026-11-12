@php use App\Domain\Student\Support\Times; @endphp
<x-student.shell title="My courses">
    <x-td.page-head title="My courses" lede="Courses you joined, where you stopped, and the certificates you earned.">
        <x-slot:actions><x-td.button variant="primary" icon="book-open" :href="route('learning.courses.index')">Browse courses</x-td.button></x-slot:actions>
    </x-td.page-head>

    <x-td.panel title="In progress" flush>
        @if ($inProgress->isEmpty())
            <x-td.state kind="empty" title="No course in progress">Courses are written by tutors and some are free. Your progress is saved lesson by lesson.
                <x-slot:actions><x-td.button :href="route('learning.courses.index')" icon="book-open">Browse courses</x-td.button></x-slot:actions>
            </x-td.state>
        @else
            <ul class="st-list">
                @foreach ($inProgress as $row)
                    @php $resume = route('learning.learn', [$row['enrolment']->course_id, $row['summary']['resume_lesson_id']]); @endphp
                    <li class="st-row">
                        <div class="st-stack st-stack--tight">
                            <p class="st-row__title"><a href="{{ $resume }}">{{ $row['title'] }}</a></p>
                            <x-td.progress label="Lessons done" :value="$row['summary']['completed']" :max="max(1, $row['summary']['total'])" :display="$row['summary']['completed'].' of '.$row['summary']['total']" :stage="$ctx['stage_colour']" />
                            @if ($row['newer'])<p class="st-row__meta">The tutor published an updated version. You can move to it from the course page.</p>@endif
                        </div>
                        <div class="st-row__end">
                            <x-td.button size="sm" variant="primary" :href="$resume">{{ $row['summary']['completed'] > 0 ? 'Resume' : 'Start' }}</x-td.button>
                            <x-td.button size="sm" variant="quiet" :href="route('learning.courses.show', $row['enrolment']->course_id)">Course page</x-td.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-td.panel>

    @if ($finished->isNotEmpty())
        <x-td.panel title="Finished" flush>
            <ul class="st-list">
                @foreach ($finished as $row)
                    <li class="st-row">
                        <div>
                            <p class="st-row__title"><a href="{{ route('learning.courses.show', $row['enrolment']->course_id) }}">{{ $row['title'] }}</a></p>
                            <p class="st-row__meta">Finished {{ Times::when($row['summary']['completed_at']) }}</p>
                        </div>
                        <div class="st-row__end"><x-td.button size="sm" :href="route('learning.courses.show', $row['enrolment']->course_id).'#review'">Review the course</x-td.button></div>
                    </li>
                @endforeach
            </ul>
        </x-td.panel>
    @endif

    <x-td.panel title="Certificates" flush>
        @if ($certificates->isEmpty())
            <x-td.state kind="empty" title="No certificates yet">Courses that offer one say so on their page. A Dot.Tutor certificate shows you completed the course; it is not an accredited qualification.</x-td.state>
        @else
            <ul class="st-list">
                @foreach ($certificates as $certificate)
                    <li class="st-row">
                        <div>
                            <p class="st-row__title">{{ $certificate->course_title }}</p>
                            <p class="st-row__meta">With {{ $certificate->tutor_name }} · {{ Times::when($certificate->issued_at) }} · code {{ $certificate->code }}@if ($certificate->revoked_at) · <strong>revoked</strong>@endif</p>
                        </div>
                        <div class="st-row__end"><x-td.button size="sm" icon="external-link" :href="route('learning.certificates.verify', $certificate->code)">Public check page</x-td.button></div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-td.panel>
</x-student.shell>
