@php $m = fn (int $minor, ?string $c = null) => \App\Domain\Teach\Support\TutorContext::money($minor, $c); @endphp
<x-layouts::workspace title="Courses">
    <x-td.page-head title="Courses" :lede="$courses->isEmpty() ? 'Make a course once and sell it to many learners. The studio walks you through it step by step.' : $courses->count().' '.\Illuminate\Support\Str::plural('course', $courses->count()).', '.$courses->where('status', \App\Domain\Learning\Enums\CourseStatus::Published)->count().' published.'">
        <x-slot:actions><x-td.button href="{{ route('teach.courses.create') }}" variant="primary" icon="plus">New course</x-td.button></x-slot:actions>
    </x-td.page-head>

    <x-td.panel title="Your courses" flush>
        @if ($courses->isEmpty())
            <div class="p-5"><x-td.state kind="empty" title="No courses yet">A course is sections of lessons: readings, videos, documents, slides, practice and quizzes. You set the subject, level, language and price, check it as a learner would, and publish when the checklist is clear.
                <x-slot:actions><x-td.button href="{{ route('teach.courses.create') }}" variant="primary" size="sm">Start your first course</x-td.button></x-slot:actions>
            </x-td.state></div>
        @else
            <x-td.table caption="Your courses" caption-hidden dense stack>
                <thead><tr><th scope="col">Course</th><th scope="col">State</th><th scope="col" class="is-num">Lessons</th><th scope="col">Price</th><th scope="col" class="is-num">Learners</th><th scope="col" class="is-num">Paid sales</th><th scope="col" class="is-num" data-stack="key">You kept</th></tr></thead>
                <tbody>
                    @foreach ($courses as $c)
                        @php $sale = $sales->get($c->id); @endphp
                        <tr>
                            <th scope="row"><a class="td-link" href="{{ route('teach.courses.show', $c) }}">{{ $c->title }}</a></th>
                            <td>{{ ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'][$c->status->value] }}{{ $c->status->value === 'published' && $c->current_version_id ? '' : '' }}</td>
                            <td class="is-num">{{ $c->lessons_count }}</td>
                            <td>{{ $c->pricing === 'paid' ? $m((int) $c->price_minor, $c->currency) : ($c->pricing === 'free' ? 'Free' : 'Not set') }}</td>
                            <td class="is-num">{{ $c->active_enrolments }}</td>
                            <td class="is-num">{{ (int) ($sale->n ?? 0) }}</td>
                            <td class="is-num">{{ $m((int) ($sale->net ?? 0), $c->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
        @endif
    </x-td.panel>

    <div class="td-panels td-panels--2 mt-6">
        <x-td.panel title="Course bundles">
            @if ($bundles->isEmpty())
                <p class="m-0 text-td-ink-2">No bundles. A bundle sells two or more of your published courses together at one price.</p>
            @else
                <ul class="m-0 grid list-none gap-2 p-0">
                    @foreach ($bundles as $b)
                        <li><strong>{{ $b->title }}</strong>, {{ $m((int) $b->price_minor, $b->currency) }} <span class="text-td-ink-2">({{ $b->courses->pluck('title')->implode(', ') }})</span></li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>
        <x-td.panel title="New bundle">
            @if ($publishedCourses->count() < 2)
                <x-td.state kind="empty" title="Needs two published courses">Publish at least two courses to bundle them.</x-td.state>
            @else
                <form method="POST" action="{{ route('teach.courses.bundles.store') }}" class="grid gap-4">
                    @csrf
                    <x-td.field label="Bundle title" for="bundle-title"><x-td.input name="title" id="bundle-title" maxlength="160" required /></x-td.field>
                    <fieldset class="grid gap-2"><legend class="td-label">Courses in it</legend>
                        @error('course_ids')<p class="td-error">{{ $message }}</p>@enderror
                        @foreach ($publishedCourses as $c)<x-td.check name="course_ids[]" id="bundle-course-{{ $c->id }}" :value="$c->id">{{ $c->title }} ({{ $c->pricing === 'paid' ? strip_tags((string) $m((int) $c->price_minor, $c->currency)) : 'free' }})</x-td.check>@endforeach
                    </fieldset>
                    <x-td.field label="Bundle price (ZAR)" for="bundle-price"><x-td.input name="price" id="bundle-price" inputmode="decimal" required /></x-td.field>
                    <div><x-td.button type="submit" variant="primary">Create bundle</x-td.button></div>
                </form>
            @endif
        </x-td.panel>
    </div>
</x-layouts::workspace>
