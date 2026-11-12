{{-- Report something to the Dot.Tutor safety team (safety's POST /safety/reports). $type: a reportable alias from
     config('safety.reports.reportable'); $id; $label: what is being reported, in words. --}}
@php $rid = 'report-'.$type.'-'.$id; @endphp
<details class="st-report">
    <summary class="td-link" style="cursor:pointer"><x-td.icon name="flag" /> Report {{ $label }}</summary>
    <form method="POST" action="{{ route('safety.reports.store') }}" class="st-form" style="margin-top:var(--td-space-3)">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        <input type="hidden" name="id" value="{{ $id }}">
        <x-td.field label="What is wrong?" for="{{ $rid }}-category">
            <x-td.select name="category" id="{{ $rid }}-category" :options="collect(\App\Domain\Safety\Enums\ReportCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" />
        </x-td.field>
        <x-td.field label="Tell us more" for="{{ $rid }}-details" optional>
            <x-td.textarea name="details" id="{{ $rid }}-details" rows="3" maxlength="2000" />
        </x-td.field>
        <p class="st-small" style="margin:0">The safety team reads every report. Reports about a learner under 18 are handled first.</p>
        <div class="st-form__actions"><x-td.button type="submit" variant="danger" size="sm">Send the report</x-td.button></div>
    </form>
</details>
