@extends('errors._ecosystem-layout', ['code' => 419])

@section('title', "Your session timed out")
@section('message', "For your safety, forms expire after a while without activity. Go back, refresh the page and send it again.")

@section('actions')
    <a class="td-btn td-btn--primary" href="{{ url()->previous() }}">Go back</a>
@endsection
