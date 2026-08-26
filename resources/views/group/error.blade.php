@extends('layouts.classic')

@section('title', __('Errors'))

@section('content')
    {{-- OpenPNE 3 joinError.php / quitError.php: the error box and the history-back line. --}}
    <x-classic.parts id="error" name="box" :title="__('Errors')">
        <div class="body">{{ $body }}</div>
    </x-classic.parts>
    <x-classic.history-back :fallback="route('group.show', $group)" />
@endsection
