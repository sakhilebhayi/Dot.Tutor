{{-- /learn/profile (academic.profile.edit): the learning profile, rendered for the academic controller (D-1103). --}}
<x-student.shell :title="__('academic.onboarding.title')">
    <x-td.page-head :title="__('academic.onboarding.title')" :lede="__('academic.onboarding.lede')">
        <x-slot:actions>
            @if (Route::has('learn.account'))<x-td.button variant="quiet" icon="languages" :href="route('learn.account')">Account and languages</x-td.button>@endif
        </x-slot:actions>
    </x-td.page-head>
    @include('student.profile.form', ['mode' => 'profile'])
</x-student.shell>
