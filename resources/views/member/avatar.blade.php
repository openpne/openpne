@extends('layouts.classic')

@section('title', __('Profile image'))

@section('content')
    <div class="dparts" id="member_avatar">
        <h2 class="partsHeading">{{ __('Profile image') }}</h2>
        <div class="parts">
            @if ($avatar)
                <p><img src="{{ $avatar->thumbnailUrl(120, 120, square: true) }}" alt="{{ __('Profile image') }}"></p>
            @else
                <p>{{ __('No profile image set.') }}</p>
            @endif
            <form method="POST" action="{{ route('member.avatar.update') }}" enctype="multipart/form-data">
                @csrf
                <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required>
                <button type="submit">{{ __('Upload') }}</button>
            </form>
        </div>
    </div>
@endsection
