{{--
    Shown with a 503 when InfoDot has switched Dot.Tutor off (maintenance or unavailable, R-61). It says so plainly and reads
    nothing of the person's lessons, bookings or payments: everything already here is kept exactly as it is. It is not
    a purchase or permission problem.
--}}
@extends('errors._ecosystem-layout', ['code' => 503])

@section('title', 'Dot.Tutor is not available right now')
@section('message', ($message ?? 'Dot.Tutor is temporarily unavailable.').' Your lessons, bookings and payments are kept as they are. Try again shortly.')

@section('actions')
    @if ($signedIn ?? false)
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" class="td-btn">Sign out</button>
        </form>
    @endif
@endsection
