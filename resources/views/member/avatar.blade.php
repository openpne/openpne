@extends('layouts.classic')

@section('title', __('Profile image'))

@section('content')
    <div class="dparts" id="member_avatar">
        <div class="partsHeading"><h3>{{ __('Profile image') }}</h3></div>
        <div class="parts">
            <p><x-classic.image :file="$avatar" :size="120" :alt="__('Profile image')" /></p>
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
