@extends('layouts.classic')

@section('title', __('block.block'))

@section('content')
    <div class="dparts" id="block_add">
        <div class="partsHeading"><h3>{{ __('block.block') }}</h3></div>
        <div class="parts">
            <p>{{ __('block.confirm_block', ['name' => $target->name]) }}</p>
            <form method="POST" action="{{ route('block.add') }}">
                @csrf
                <input type="hidden" name="target_id" value="{{ $target->getKey() }}">
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('block.block') }}"></li>
                        <li><a href="{{ route('block.list') }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
