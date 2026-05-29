@extends('layouts.classic')

@section('title', __('Unblock'))

@section('content')
    <div class="dparts" id="block_remove">
        <h2 class="partsHeading">{{ __('Unblock') }}</h2>
        <div class="parts">
            <p>{{ __('Unblock :name?', ['name' => $target->name]) }}</p>
            <form method="POST" action="{{ route('block.remove.submit', $target) }}">
                @csrf
                <button type="submit">{{ __('Unblock') }}</button>
            </form>
            <a href="{{ route('block.list') }}">{{ __('Cancel') }}</a>
        </div>
    </div>
@endsection
