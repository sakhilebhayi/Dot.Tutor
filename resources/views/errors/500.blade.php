@extends('errors._ecosystem-layout', ['code' => 500])

@section('title', "Something went wrong on our end")
@section('message', "An unexpected error stopped this page. It is not something you did. Try again in a moment; if it keeps happening, tell us and we will look into it.")

@section('actions')
    <a class="td-btn td-btn--primary" href="{{ url('/') }}">Go to the home page</a>
@endsection
