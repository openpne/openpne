@extends('layouts.classic')

@section('title', __('Delete %diary%'))

@section('content')
    <x-classic.parts id="formDiaryDelete" name="box" :title="__('Delete %diary%')">
        <div class="block">
            <p>{{ __('Delete ":title"?', ['title' => $diary->title]) }}</p>
            <form method="POST" action="{{ route('diary.delete', $diary) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                        <li><a href="{{ route('diary.show', $diary) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </x-classic.parts>
@endsection
