@extends('layouts.classic')

@section('title', __('Profile image'))

@section('content')
    <div class="dparts" id="member_avatar">
        <div class="partsHeading"><h3>{{ __('Profile image') }}</h3></div>
        <div class="parts">
            @if ($avatar)
                <p><img src="{{ $avatar->thumbnailUrl(120, 120, square: true) }}" alt="{{ __('Profile image') }}"></p>
            @else
                <p>{{ __('No profile image set.') }}</p>
            @endif
            <form method="POST" action="{{ route('member.avatar.update') }}" enctype="multipart/form-data">
                @csrf
                <input type="file" class="input_file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Upload') }}"></li>
                    </ul>
                </div>
            </form>
            @if ($avatar)
                <form method="POST" action="{{ route('member.avatar.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <div class="operation">
                        <ul class="moreInfo button">
                            <li><input type="submit" class="input_submit" value="{{ __('Remove') }}"></li>
                        </ul>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection
