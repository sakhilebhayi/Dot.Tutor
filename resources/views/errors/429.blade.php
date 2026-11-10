@extends('errors._ecosystem-layout', ['code' => 429])

@section('title', "Slow down a little")
@section('message', "Too many requests arrived in a short time. This limit protects everyone. Wait a minute, then try again.")

@section('actions')
    <a class="td-btn td-btn--primary" href="{{ url('/') }}">Go to the home page</a>
@endsection
