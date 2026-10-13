@extends('layouts.app')

@section('title', 'My Procurement Requests - Loveworld Business Registry')

@section('content')
    <div class="container auth-dashboard-shell">
        <livewire:sbo.rfqs.index />
    </div>
@endsection
