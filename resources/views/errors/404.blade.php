@extends('errors._ecosystem-layout', ['code' => 404])

@section('title', "We couldn't find that page")
@section('message', "This shelf is empty: the page may have moved, been renamed, or never existed. Check the link, or start again from a page we know works.")

@section('actions')
    <a class="td-btn td-btn--primary" href="{{ url('/') }}">Go to the home page</a>
    @if (Route::has('tutors.browse'))
        <a class="td-btn" href="{{ route('tutors.browse') }}">Find a tutor</a>
    @endif
@endsection
