@extends('layouts.classic')

@section('title', __('Unblock'))

@section('content')
    <div class="dparts" id="block_remove">
        <div class="partsHeading"><h3>{{ __('Unblock') }}</h3></div>
        <div class="parts">
            <p>{{ __('Unblock :name?', ['name' => $target->name]) }}</p>
            <form method="POST" action="{{ route('block.remove.submit', $target) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Unblock') }}"></li>
                        <li><a href="{{ route('block.list') }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
