{{-- Terms of Service: the Markdown in resources/markdown rendered in the site's own type and panel (R-47 polish D-2015), in
     the public shell like every other page a visitor reads. The text has not had a legal review (docs/R47_POLISH_LEARNER.md). --}}
<x-layouts::public title="Terms of Service" description="The terms for using Dot.Tutor: accounts, tutors, learners and guardians, reviews, acceptable use, verification and closing your account.">
    @include('partials.legal', ['title' => 'Terms of Service', 'html' => $terms])
</x-layouts::public>
