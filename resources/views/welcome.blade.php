@extends('layouts.app')
@section('title','Welcome')
@section('content')
<section class="panel p-5">
    <span class="nav-label">BANAS PUMP MANAGER</span>
    <h1 class="mt-2">Welcome to your operations workspace</h1>
    <p class="subtle">Your daily sales, shifts and station records in one place.</p>
    <a class="btn btn-primary mt-3" href="{{ route('dashboard') }}">Open day summary</a>
</section>
@endsection
