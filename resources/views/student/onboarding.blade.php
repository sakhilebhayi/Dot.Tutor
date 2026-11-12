{{-- /learn/welcome: the first-run profile in under a minute (D-1106). Data from the academic profile controller. --}}
<x-student.shell title="Welcome">
    <x-td.page-head title="Let's get you the right help"
        lede="Your grade, the subjects you want help with and the languages you learn best in. Only your grade and month of birth are needed; skip anything else." />
    @include('student.profile.form', ['mode' => 'onboarding'])
</x-student.shell>
