@extends('layouts.classic')

@section('title', __('Edit %topic%'))

@section('content')
    <div class="dparts form" id="communityTopic_edit">
        <div class="partsHeading"><h3>{{ __('Edit %topic%') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('communityTopic.update', $topic) }}">
                @csrf
                <table>
                    @include('community-topic._fields', ['name' => $topic->name, 'body' => $topic->body])
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                        <li><a href="{{ route('communityTopic.show', $topic) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
