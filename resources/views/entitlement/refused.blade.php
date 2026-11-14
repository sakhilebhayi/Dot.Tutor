{{-- R-50: the company does not hold Dot.Tutor. Rendered by App\Support\EntitlementRefusal with HTTP 403, inside the
     shared error shell. It shows nothing of the person's lessons, bookings or payments. ONE primary action
     (data-primary-action): the way to Platforms in InfoDot. Switching company and signing out are quiet. --}}
@extends('errors._ecosystem-layout', ['code' => 403, 'errorLabel' => $stamp, 'entitlementRefused' => true])

@section('title', $title)
@section('message', $body)

@section('actions')
    <x-td.button variant="primary" size="lg" :href="$platformsUrl" icon-end="arrow-right" data-primary-action>Open Platforms in InfoDot</x-td.button>
@endsection

@section('after-actions')
    @auth
        <div class="err__more">
            @if ($otherCompanies->isNotEmpty())
                <section aria-labelledby="gate-others">
                    <h2 id="gate-others" class="err__eco-title">Your other {{ $otherCompanies->count() === 1 ? 'company' : 'companies' }} here</h2>
                    <ul class="err__companies">
                        @foreach ($otherCompanies as $other)
                            <li>
                                <b>{{ $other->name }}</b>
                                <form method="POST" action="{{ route('current-team.update') }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="team_id" value="{{ $other->id }}">
                                    <x-td.button variant="quiet" size="sm" type="submit" aria-label="Switch to {{ $other->name }}">Switch</x-td.button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-td.button variant="quiet" size="sm" type="submit">Sign out</x-td.button>
            </form>
        </div>
    @endauth
@endsection

@section('local-routes')
@endsection
