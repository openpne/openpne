@extends('layouts.classic')

@section('title', __('Post a new %topic%'))

@section('content')
    <div class="dparts form" id="communityTopic_new">
        <div class="partsHeading"><h3>{{ __('Post a new %topic%') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('communityTopic.store', $community) }}" enctype="multipart/form-data">
                @csrf
                <table>
                    @include('community-topic._fields')
                    @include('community-topic._image_fields')
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Post') }}"></li>
                        <li><a href="{{ route('communityTopic.index', $community) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
