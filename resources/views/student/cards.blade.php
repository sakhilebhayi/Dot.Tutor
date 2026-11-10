{{-- One flashcard at a time (D-1115). The back sits behind a disclosure so it works without script; the grade buttons
     post to the learning domain's spaced-repetition review. --}}
<x-student.shell :title="$set->title">
    <x-td.page-head :title="$set->title" :lede="$left === 0 ? 'Nothing to review right now.' : $left.' '.\Illuminate\Support\Str::plural('card', $left).' to review now.'">
        <x-slot:actions><x-td.button variant="quiet" icon="arrow-left" :href="route('practice.index')">Practice</x-td.button></x-slot:actions>
    </x-td.page-head>

    @if ($card)
        <section class="st-card" aria-labelledby="card-front">
            <p class="st-small" style="margin:0">Question</p>
            <p class="st-card__front" id="card-front">{{ $card->front }}</p>
            <details>
                <summary>Show the answer</summary>
                <div class="st-stack" style="margin-top:var(--td-space-4)">
                    <p class="st-card__front" style="font-weight:400">{{ $card->back }}</p>
                    <p class="st-small" style="margin:0">How well did you know it? Cards you did not know come back tomorrow; cards you knew come back later each time.</p>
                    <form method="POST" action="{{ route('practice.cards.grade', [$set->id, $card->id]) }}" class="st-grades">
                        @csrf
                        <x-td.button type="submit" name="grade" value="1">I did not know it</x-td.button>
                        <x-td.button type="submit" name="grade" value="3">I got it with effort</x-td.button>
                        <x-td.button type="submit" name="grade" value="5" variant="primary">I knew it</x-td.button>
                    </form>
                </div>
            </details>
        </section>
    @else
        <x-td.state kind="success" title="All done for now">Every card in this set is scheduled for later. Come back when they are due.
            <x-slot:actions><x-td.button :href="route('practice.index')">Back to practice</x-td.button></x-slot:actions>
        </x-td.state>
    @endif
</x-student.shell>
