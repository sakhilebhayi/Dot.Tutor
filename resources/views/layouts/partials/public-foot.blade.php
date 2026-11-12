{{-- Public footer. $credits: list of picture credits for photographs on the page (title, creator, licence, url). --}}
<footer class="td-public-foot">
    <div class="td-wrap td-public-foot__grid">
        <div>
            <a class="td-wordmark" href="{{ url('/') }}" style="color:inherit">
                <img src="{{ asset('images/mark-64.png') }}" alt="" width="32" height="32">
                <span>Dot.Tutor</span>
            </a>
            <p style="max-width:34ch;margin:var(--td-space-4) 0 0">Learning and tutoring from Grade 1 to doctoral study, in the languages South Africa speaks. Part of the Dot ecosystem; sign in with your InfoDot account.</p>
        </div>
        <div class="td-public-foot__links">
            <div>
                <h2>Learn</h2>
                <ul>
                    <li><a href="{{ url('/#how') }}">How matching works</a></li>
                    <li><a href="{{ url('/#rooms') }}">Rooms by level</a></li>
                    <li><a href="{{ url('/#languages') }}">Languages</a></li>
                </ul>
            </div>
            <div>
                <h2>Teach</h2>
                <ul>
                    <li><a href="{{ url('/#teach') }}">Tutor on Dot.Tutor</a></li>
                    @if (Route::has('login'))<li><a href="{{ route('login') }}">Sign in</a></li>@endif
                </ul>
            </div>
            <div>
                <h2>Policies</h2>
                <ul>
                    @if (Route::has('terms.show'))<li><a href="{{ route('terms.show') }}">Terms</a></li>@endif
                    @if (Route::has('policy.show'))<li><a href="{{ route('policy.show') }}">Privacy</a></li>@endif
                    @if (Route::has('cookies'))<li><a href="{{ route('cookies') }}">Cookies</a></li>@endif
                </ul>
            </div>
        </div>
    </div>
    <div class="td-wrap td-public-foot__legal">
        <span>&copy; {{ date('Y') }} Dot.Tutor, an InfoDot ecosystem platform</span>
        @if (! empty($credits))
            <span>
                Photographs:
                @foreach ($credits as $credit)
                    <a href="{{ $credit['url'] }}" rel="noopener">{{ $credit['title'] }}</a>{{ $credit['creator'] ? ' by '.$credit['creator'] : '' }}, {{ $credit['licence'] }}@if (! $loop->last); @endif
                @endforeach
            </span>
        @endif
    </div>
</footer>
