@php $kindWords = ['course_quiz' => 'Course quiz', 'tutor_assessment' => 'Assessment for your students', 'practice' => 'Practice']; @endphp
<x-layouts::workspace title="Question banks and assessments" step="dense">
    <x-td.page-head title="Materials" lede="Question banks hold your questions by topic; assessments draw on them and go to the students you teach. Marking is automatic, and answers stay hidden until a learner submits." />
    @include('teach.partials.materials-tabs')

    <div class="td-panels md:grid-cols-2">
        <x-td.panel title="Question banks">
            @forelse ($banks as $b)
                <p class="m-0 flex flex-wrap justify-between gap-2 border-t border-td-line py-2 first:border-t-0">
                    <a class="td-link" href="{{ route('teach.materials.banks.show', $b->id) }}">{{ $b->title }}</a>
                    <span class="text-td-ink-2">{{ $b->topic }}, {{ $b->questions_count }} {{ \Illuminate\Support\Str::plural('question', $b->questions_count) }}</span>
                </p>
            @empty
                <x-td.state kind="empty" title="No question banks">Make one bank per topic, for example "Grade 10 functions". Questions are retired, never edited, so past marks stay valid.</x-td.state>
            @endforelse
            <form method="POST" action="{{ route('teach.materials.banks.store') }}" class="mt-4 grid gap-3 border-t border-td-line pt-4">
                @csrf
                <x-td.field label="Bank title" for="bank-title"><x-td.input name="title" id="bank-title" maxlength="160" required /></x-td.field>
                <x-td.field label="Topic" for="bank-topic"><x-td.input name="topic" id="bank-topic" maxlength="120" required /></x-td.field>
                <x-td.field label="Strand" for="bank-strand" optional hint="For example: Algebra."><x-td.input name="strand" id="bank-strand" maxlength="120" :hint="true" /></x-td.field>
                <div><x-td.button type="submit" variant="primary" icon="plus">Create bank</x-td.button></div>
            </form>
        </x-td.panel>

        <x-td.panel title="Assessments">
            @forelse ($assessments as $a)
                <p class="m-0 grid gap-1 border-t border-td-line py-2 first:border-t-0">
                    <a class="td-link" href="{{ route('teach.materials.assessments.show', $a->id) }}">{{ $a->title }}</a>
                    <span class="text-sm text-td-ink-2">{{ $kindWords[$a->kind->value] ?? $a->kind->value }}{{ $a->course ? ' in '.$a->course->title : '' }}: {{ $a->questions_count }} questions, {{ $a->assignments_count }} assigned, {{ $a->submitted_attempts }} submitted</span>
                </p>
            @empty
                <x-td.state kind="empty" title="No assessments">An assessment can be set for your own students: anyone with a confirmed or completed lesson, or enrolled in your course.</x-td.state>
            @endforelse
            <form method="POST" action="{{ route('teach.materials.assessments.store') }}" class="mt-4 grid gap-3 border-t border-td-line pt-4">
                @csrf
                <x-td.field label="Title" for="assessment-title"><x-td.input name="title" id="assessment-title" maxlength="160" required /></x-td.field>
                <x-td.field label="For" for="kind"><x-td.select name="kind" :options="['tutor_assessment' => 'Students I assign it to', 'practice' => 'A practice set']" /></x-td.field>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-td.field label="Pass mark (%)" for="pass_percent"><x-td.input name="pass_percent" type="number" min="0" max="100" value="50" /></x-td.field>
                    <x-td.field label="Time limit (min)" for="time_limit_minutes" optional><x-td.input name="time_limit_minutes" type="number" min="1" max="600" /></x-td.field>
                    <x-td.field label="Attempts" for="max_attempts" optional><x-td.input name="max_attempts" type="number" min="1" max="100" /></x-td.field>
                </div>
                <div><x-td.button type="submit" variant="primary" icon="plus">Create assessment</x-td.button></div>
            </form>
        </x-td.panel>
    </div>
</x-layouts::workspace>
