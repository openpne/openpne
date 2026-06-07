@extends('layouts.classic')

@section('title', __('Delete %topic%'))

@section('content')
    <div class="dparts box" id="communityTopic_delete">
        <div class="partsHeading"><h3>{{ __('Delete %topic%') }}</h3></div>
        <div class="parts">
            <div class="block">
                <p>{{ __('Delete :name? This cannot be undone.', ['name' => $topic->name]) }}</p>
                <form method="POST" action="{{ route('communityTopic.delete', $topic) }}">
                    @csrf
                    <div class="operation">
                        <ul class="moreInfo button">
                            <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                            <li><a href="{{ route('communityTopic.show', $topic) }}">{{ __('Cancel') }}</a></li>
                        </ul>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
