{{-- /learning/courses (learning.courses.index): the course catalogue (D-1118). From the learning controller: $courses
     (CoursePresenter rows), $paginator, $filters. LearningComposer adds the choice lists. --}}
@php $f = $filters; @endphp
<x-student.shell title="Courses">
    <x-td.page-head title="Courses" lede="Courses written by tutors on Dot.Tutor. Each one shows its price, its language, its level and what you will learn before you join.">
        <x-slot:actions>@if (Route::has('learn.courses'))<x-td.button variant="quiet" icon="book-open" :href="route('learn.courses')">My courses</x-td.button>@endif</x-slot:actions>
    </x-td.page-head>

    <x-td.panel title="Filter">
        <form method="GET" action="{{ route('learning.courses.index') }}" role="search" class="st-form">
            <x-td.field label="Search" for="q"><x-dot.filter-search name="q" id="q" label="Search" :value="$f['q'] ?? ''" maxlength="100" placeholder="Title or description" /></x-td.field>
            <div class="st-form__pair">
                <x-td.field label="Subject" for="subject_id"><x-td.select name="subject_id" :value="$f['subject_id'] ?? null" placeholder="Any subject" :options="$subjectOptions" /></x-td.field>
                <x-td.field label="Grade or level" for="level_id"><x-td.select name="level_id" :value="$f['level_id'] ?? null" placeholder="Any level" :options="$levelOptions" /></x-td.field>
                <x-td.field label="Curriculum" for="curriculum_id"><x-td.select name="curriculum_id" :value="$f['curriculum_id'] ?? null" placeholder="Any curriculum" :options="$curriculumOptions" /></x-td.field>
                <x-td.field label="Taught in" for="language"><x-td.select name="language" :value="$f['language'] ?? null" placeholder="Any language" :options="$languageOptions" /></x-td.field>
                <x-td.field label="Price" for="pricing"><x-td.select name="pricing" :value="$f['pricing'] ?? null" placeholder="Free and paid" :options="['free' => 'Free', 'paid' => 'Paid']" /></x-td.field>
            </div>
            <div class="st-form__actions"><x-td.button type="submit" variant="primary" icon="search">Show courses</x-td.button>
                @if (array_filter($f))<x-td.button variant="quiet" :href="route('learning.courses.index')">Clear</x-td.button>@endif</div>
        </form>
    </x-td.panel>

    <x-td.panel :title="$paginator->total().' '.\Illuminate\Support\Str::plural('course', $paginator->total())" flush>
        @if ($courses->isEmpty())
            <x-td.state kind="empty" title="No published course matches">Try fewer filters. Tutors publish new courses from their course studio.</x-td.state>
        @else
            <ul class="st-list">
                @foreach ($courses as $course)
                    <li class="st-row">
                        <div class="st-stack st-stack--tight">
                            <p class="st-row__title"><a href="{{ route('learning.courses.show', $course['id']) }}">{{ $course['title'] }}</a></p>
                            <p class="st-row__meta st-inline st-dot">
                                <span>By {{ $course['tutor']['name'] }}</span>
                                <span>{{ $course['academic']['subject'] ?? 'Subject not set' }}</span>
                                @if ($course['academic']['level'] ?? null)<span>{{ $course['academic']['level'] }}</span>@endif
                                <span>Taught in {{ $languageNames[$course['language']] ?? $course['language'] }}</span>
                                @if ($course['workload_minutes'])<span>About {{ max(1, (int) round($course['workload_minutes'] / 60)) }} {{ \Illuminate\Support\Str::plural('hour', max(1, (int) round($course['workload_minutes'] / 60))) }}</span>@endif
                                @if ($course['reviews']['count'] > 0)<span>{{ $course['reviews']['average'] }} out of 5 from {{ $course['reviews']['count'] }}</span>@endif
                            </p>
                        </div>
                        <div class="st-row__end"><span class="st-title">@if ($course['pricing'] === 'free') Free @else<x-td.money :minor="$course['price_minor']" :currency="$course['currency']" />@endif</span></div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-td.panel>
    <x-td.pagination :paginator="$paginator" label="Course pages" />
</x-student.shell>
