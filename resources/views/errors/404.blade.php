@extends('errors._ecosystem-layout', ['code' => 404])

@section('title', "We couldn't find that page")
@section('message', "This shelf is empty: the page may have moved, been renamed, or never existed. Check the link, or carry on from one of these.")

@section('actions')
    @if (Route::has('marketplace.find'))
        <a class="td-btn td-btn--primary" href="{{ route('marketplace.find') }}"><span>Find a tutor</span></a>
    @endif
    <a class="td-btn" href="{{ url('/#rooms') }}"><span>Rooms by level</span></a>
    <a class="td-btn td-btn--quiet" href="{{ url('/') }}"><span>Home page</span></a>
@endsection

@section('local-routes', '1')
