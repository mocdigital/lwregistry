@extends('layouts.app')

@section('title', 'Application Progress - Loveworld Business Registry')

@section('content')
    <div class="container auth-dashboard-shell">
        <livewire:sbo.applications.progress :sbo="$sbo" />
    </div>
@endsection
