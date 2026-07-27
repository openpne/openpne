@extends('layouts.classic')

@section('title', __('Unblock'))

@section('content')
    <x-classic.parts id="block_remove" name="box" :title="__('Unblock')">
        <div class="block">
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
    </x-classic.parts>
@endsection
