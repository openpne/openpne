@extends('layouts.classic')

@section('title', __('Block'))

@section('content')
    <div class="dparts" id="block_add">
        <div class="partsHeading"><h3>{{ __('Block') }}</h3></div>
        <div class="parts">
            <p>{{ __('Block :name?', ['name' => $target->name]) }}</p>
            <form method="POST" action="{{ route('block.add') }}">
                @csrf
                <input type="hidden" name="target_id" value="{{ $target->getKey() }}">
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><button type="submit" class="input_submit">{{ __('Block') }}</button></li>
                        <li><a href="{{ route('block.list') }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
