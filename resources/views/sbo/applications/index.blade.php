@extends('layouts.app')

@section('title', 'SBO Applications - Loveworld Business Registry')

@section('content')
    <div class="container auth-dashboard-shell">
        <livewire:sbo.applications.index />
    </div>
@endsection
