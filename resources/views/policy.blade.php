{{-- Privacy Policy: the Markdown in resources/markdown rendered in the site's own type and panel (R-47 polish D-2015), in
     the public shell like every other page a visitor reads. The text has not had a legal review (docs/R47_POLISH_LEARNER.md). --}}
<x-layouts::public title="Privacy Policy" description="What personal information Dot.Tutor holds, why, who can see it, how long it is kept and your rights under POPIA.">
    @include('partials.legal', ['title' => 'Privacy Policy', 'html' => $policy])
</x-layouts::public>
