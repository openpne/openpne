@extends('layouts.classic')

@section('title', __('Delete %diary%'))

@section('content')
    <x-classic.parts id="formDiaryDelete" name="box" :hand-written="true" :title="__('Delete %diary%')">
        <div class="block">
            <p>{{ __('Delete ":title"?', ['title' => $diary->title]) }}</p>
            <form method="POST" action="{{ route('diary.delete', $diary) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </x-classic.parts>
    <x-classic.history-back :fallback="route('diary.show', $diary)" />
@endsection
