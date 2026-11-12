{{-- Cookie Policy: the Markdown in resources/markdown rendered in the site's own type and panel (R-47 polish D-2015), in
     the public shell like every other page a visitor reads. The text has not had a legal review (docs/R47_POLISH_LEARNER.md). --}}
<x-layouts::public title="Cookie Policy" description="The cookies Dot.Tutor uses: only those needed to keep you signed in and protect your forms. No advertising or tracking cookies.">
    @include('partials.legal', ['title' => 'Cookie Policy', 'html' => $cookies])
</x-layouts::public>
