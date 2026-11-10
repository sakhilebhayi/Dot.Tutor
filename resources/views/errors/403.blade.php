@extends('errors._ecosystem-layout', ['code' => 403])

@section('title', "You don't have access to this")
@section('message', "It may belong to a different team, need a role you do not have, or need you to sign in first. If you think this is wrong, ask whoever runs this workspace.")

@section('actions')
    <a class="td-btn td-btn--primary" href="{{ url('/') }}">Go to the home page</a>
@endsection
