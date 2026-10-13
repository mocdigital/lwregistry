@extends('layouts.app')

@section('title', 'SBO Network - Loveworld Business Registry')

@section('content')
    <main class="container auth-dashboard-shell sbo-network-shell">
        @livewire('sbo.network.index')
    </main>
@endsection
