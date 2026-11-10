<x-app-layout>
    <x-slot name="header">Resource library</x-slot>
    @include('learning.partials.styles')
    <div class="ln">
        <p><a href="{{ route('learning.library.index') }}">Back to the library</a></p>
        <h1>{{ $data['title'] }}</h1>
        <p class="row muted">
            <span>{{ $data['type_label'] }}</span>
            <span>Shared by {{ $data['contributor'] }}</span>
            <span class="tag {{ in_array($data['quality_tier'], ['reviewed', 'officially_sourced'], true) ? 'ok' : '' }}">{{ $data['quality_label'] }}</span>
            @if ($data['reviewed_by']) <span>Reviewed by {{ $data['reviewed_by']['name'] }} on {{ $data['reviewed_by']['at'] }}</span> @endif
            @if ($data['status'] !== 'published') <span class="tag warn">{{ str_replace('_', ' ', $data['status']) }}</span> @endif
        </p>
        @include('learning.partials.flash')
        <div class="grid two">
            <div>
                @if ($data['description'])<p class="prose">{{ $data['description'] }}</p>@endif
                <table>
                    <tr><th scope="row">Subject</th><td>{{ $data['academic']['subject'] ?? 'Not stated' }}</td></tr>
                    <tr><th scope="row">Topic</th><td>{{ $data['topic'] ?? 'Not stated' }}</td></tr>
                    <tr><th scope="row">Country, language</th><td>{{ $data['country_code'] ?? 'Any' }}, {{ $data['language'] }}</td></tr>
                    <tr><th scope="row">Difficulty</th><td>{{ $data['difficulty'] ? ucfirst($data['difficulty']) : 'Not stated' }}</td></tr>
                    @if ($data['institution'])<tr><th scope="row">Institution</th><td>{{ $data['institution'] }}</td></tr>@endif
                    <tr><th scope="row">Licence</th><td>{{ $data['licence_label'] }}{{ $data['ownership'] === 'third_party' ? ', shared from another author' : ', the contributor\'s own work' }}</td></tr>
                    @if ($data['source_url'])<tr><th scope="row">Source</th><td><a href="{{ $data['source_url'] }}" rel="noopener noreferrer" target="_blank">{{ $data['source_url'] }}</a></td></tr>@endif
                    <tr><th scope="row">File</th><td>{{ $data['mime'] }}, {{ number_format($data['size'] / 1024) }} KB</td></tr>
                    <tr><th scope="row">Recommended by</th><td>{{ $data['recommendations'] }} {{ \Illuminate\Support\Str::plural('person', $data['recommendations']) }}</td></tr>
                </table>
                @if ($data['reviews'])
                    <h2>Review record</h2>
                    <ul class="plain">@foreach ($data['reviews'] as $review)<li>{{ $review['reviewer'] }}: {{ str_replace('_', ' ', $review['outcome']) }}{{ $review['notes'] ? ' ('.$review['notes'].')' : '' }} <span class="muted">{{ \Illuminate\Support\Carbon::parse($review['at'])->toDateString() }}</span></li>@endforeach</ul>
                @endif
                @if ($data['reports'])
                    <h2>Reports on this resource</h2>
                    <ul class="plain">
                        @foreach ($data['reports'] as $report)
                            <li>
                                <strong>{{ ucfirst($report['kind']) }}</strong>, {{ $report['status'] }}: {{ $report['details'] }}
                                @if ($data['is_owner'] && $report['kind'] === 'copyright' && in_array($report['status'], ['open', 'upheld'], true))
                                    <form method="POST" action="{{ route('learning.library.reports.counter', $report['id']) }}">@csrf
                                        <label for="counter-{{ $report['id'] }}">Your counter-notice</label>
                                        <textarea id="counter-{{ $report['id'] }}" name="statement" minlength="20" required></textarea>
                                        <button class="btn ghost" type="submit">Send counter-notice</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <aside class="panel">
                @if ($data['can_download'])
                    <a class="btn" href="{{ route('learning.library.download', $resource) }}">Download</a>
                @elseif (! $data['is_free'])
                    <p><strong>@include('learning.partials.money', ['minor' => $data['price_minor'], 'currency' => $data['currency']])</strong></p>
                    <p class="hint">Payments are handled at checkout.</p>
                @endif
                @unless ($data['is_owner'])
                    <form method="POST" action="{{ route('learning.library.recommend', $resource) }}" style="margin-top:1rem">@csrf
                        @if ($data['recommended_by_you']) @method('DELETE') <button class="btn ghost" type="submit">Remove my recommendation</button>
                        @else <button class="btn ghost" type="submit">Recommend</button> @endif
                    </form>
                @else
                    <form method="POST" action="{{ route('learning.library.withdraw', $resource) }}" style="margin-top:1rem">@csrf<button class="btn danger" type="submit">Withdraw</button></form>
                @endunless
                <details style="margin-top:1.5rem">
                    <summary>Report or suggest a change</summary>
                    <form method="POST" action="{{ route('learning.library.reports.store', $resource) }}">@csrf
                        <label for="kind">What is it about?</label>
                        <select id="kind" name="kind">
                            <option value="incorrect">Something is wrong</option>
                            <option value="improvement">Suggest an improvement</option>
                            @unless ($data['is_owner'])<option value="copyright">Copyright: this is my work, shared without permission</option>@endunless
                            <option value="other">Something else</option>
                        </select>
                        <label for="details">Details</label>
                        <textarea id="details" name="details" minlength="10" required></textarea>
                        <p class="hint">A copyright notice also needs the rights holder's name, a link to the original work and the good-faith statement below. A complete notice hides the file until a moderator decides.</p>
                        <label for="claimant">Rights holder's name (copyright only)</label>
                        <input id="claimant" name="claimant_name" type="text" maxlength="160">
                        <label for="work">Link to the original work (copyright only)</label>
                        <input id="work" name="work_reference" type="text" maxlength="2048">
                        <label><input type="checkbox" name="good_faith" value="1"> I state in good faith that this use is not authorised by the rights holder (copyright only).</label>
                        <p style="margin-top:1rem"><button class="btn ghost" type="submit">Send report</button></p>
                    </form>
                </details>
            </aside>
        </div>
    </div>
</x-app-layout>
