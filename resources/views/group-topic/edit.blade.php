@extends('layouts.classic')

@section('title', __('Edit %topic%'))

@section('content')
    <x-classic.parts id="formCommunityTopic" name="form" :title="__('Edit %topic%')">
        <form method="POST" action="{{ route('group.topics.update', $topic) }}" enctype="multipart/form-data">
            @csrf
            <x-classic.required-notice />
            <table>
                @include('group-topic._fields', ['name' => $topic->name, 'body' => $topic->body, 'format' => $topic->format])
                <x-classic.photo-rows kind="topic" :images="$topic->images" />
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                    <li><a href="{{ route('group.topics.show', $topic) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>

    {{-- OpenPNE 3 editSuccess.php's buttonBox kind, body-less: the kind puts its form inside the
         operation li. --}}
    <x-classic.parts id="toDelete" name="buttonBox" :title="__('Delete the %topic% and comments')">
        <div class="operation">
            <ul class="moreInfo button">
                <li>
                    <form method="GET" action="{{ route('group.topics.delete.show', $topic) }}">
                        <input type="submit" class="input_submit" value="{{ __('Delete') }}">
                    </form>
                </li>
            </ul>
        </div>
    </x-classic.parts>
@endsection
