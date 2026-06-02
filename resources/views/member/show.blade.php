@extends('layouts.classic')

@section('title', $owner->name)

@section('content')
    <div class="dparts" id="member_profile">
        <h2 class="partsHeading">{{ $owner->name }}</h2>
        <div class="parts">
            @php($avatar = $owner->primaryImage?->file)
            @if ($avatar)
                <p><img src="{{ $avatar->thumbnailUrl(120, 120, square: true) }}" alt="{{ $owner->name }}"></p>
            @endif

            @if ($fields->isEmpty())
                <p>{{ __('No profile to show.') }}</p>
            @else
                <table class="listBox">
                    @foreach ($fields as $field)
                        <tr>
                            <th>{{ $field->profile->getCaption($lang) }}</th>
                            <td>{{ $field->display($lang) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if ($isSelf)
                <p>
                    <a href="{{ route('member.profile.edit') }}">{{ __('Edit Profile') }}</a>
                    <a href="{{ route('member.avatar.edit') }}">{{ __('Profile image') }}</a>
                </p>
            @endif
        </div>
    </div>
@endsection
