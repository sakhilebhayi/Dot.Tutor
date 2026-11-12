@php $languageOptions = collect($languages)->map(fn ($l) => ['value' => $l['code'], 'label' => $l['endonym'] === $l['name'] ? $l['name'] : $l['endonym'].' ('.$l['name'].')'])->all(); @endphp
<x-layouts::workspace title="New course">
    <x-td.page-head title="New course" lede="Start with the basics. You add outcomes, lessons, quizzes and the price next, and nothing is public until you publish.">
        <x-slot:actions><x-td.button href="{{ route('teach.courses') }}" icon="arrow-left">Courses</x-td.button></x-slot:actions>
    </x-td.page-head>
    <x-td.panel title="The basics">
        <form method="POST" action="{{ route('teach.courses.store') }}" class="grid max-w-2xl gap-5">
            @csrf
            <x-td.field label="Course title" for="title" hint="Say who it is for and what they get. For example: Grade 11 Physical Sciences, Newton's laws in four weeks.">
                <x-td.input name="title" maxlength="160" required :hint="true" />
            </x-td.field>
            <x-td.field label="Subject" for="subject_id"><x-td.select name="subject_id" :options="$subjects" placeholder="Choose a subject" /></x-td.field>
            <x-td.field label="Level" for="level_id" optional hint="The curriculum comes with the level you choose.">
                <x-td.select name="level_id" :options="$levels" placeholder="Choose a level" :hint="true" />
            </x-td.field>
            <x-td.field label="Language it is taught in" for="original_locale"><x-td.select name="original_locale" :options="$languageOptions" value="en" /></x-td.field>
            <x-td.field label="Short description" for="description" optional hint="You can write the full description later."><x-td.textarea name="description" rows="3" :hint="true" /></x-td.field>
            <div><x-td.button type="submit" variant="primary">Create draft</x-td.button></div>
        </form>
    </x-td.panel>
</x-layouts::workspace>
