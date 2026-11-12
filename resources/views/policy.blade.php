<x-guest-layout>
    <div class="pt-4 bg-[var(--paper)]">
        <div class="min-h-screen flex flex-col items-center pt-10 sm:pt-16 px-5 pb-16">
            <div>
                <x-authentication-card-logo />
            </div>

            <div class="w-full sm:max-w-2xl mt-8 p-6 sm:p-10 bg-[var(--td-surface)] border border-[var(--line)] shadow-lg overflow-hidden sm:rounded-xl prose prose-neutral max-w-none prose-headings:font-display prose-headings:text-[var(--ink)] prose-p:text-[var(--moss)] prose-li:text-[var(--moss)] prose-a:text-[var(--ink)] prose-strong:text-[var(--ink)] prose-em:text-[var(--moss)] prose-th:text-[var(--ink)] prose-td:text-[var(--moss)] prose-code:text-[var(--ink)] prose-blockquote:text-[var(--moss)] hover:prose-a:text-[var(--leaf)]">
                <h1>Privacy Policy</h1>
                {!! $policy !!}
            </div>
        </div>
    </div>
</x-guest-layout>
