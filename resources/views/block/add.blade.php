@extends('layouts.classic')

@section('title', __('Block'))

@section('content')
    {{-- Block is OpenPNE 4-native, so the confirm page follows OpenPNE 3's box-kind confirm shape
         (deleteConfirmSuccess.php) under the OpenPNE 4 id. --}}
    <x-classic.parts id="block_add" name="box" :title="__('Block')">
        <div class="block">
            <p>{{ __('Block :name?', ['name' => $target->name]) }}</p>
            <form method="POST" action="{{ route('block.add') }}">
                @csrf
                <input type="hidden" name="target_id" value="{{ $target->getKey() }}">
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Block') }}"></li>
                        <li><a href="{{ route('block.list') }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </x-classic.parts>
@endsection
