@extends('layouts.classic')

@section('title', $owner->name)

@php($zones = $zones ?? [])
@php($hasGadgets = collect($zones)->flatten(1)->isNotEmpty())

@if ($hasGadgets)
    @include('partials.gadget-sections', ['zones' => $zones])
@else
    @section('content')
        {{-- No profile gadgets configured: the fixed profile box (avatar + values + own-page links). --}}
        <div class="dparts" id="member_profile">
            <div class="partsHeading"><h3>{{ $owner->name }}</h3></div>
            <div class="parts">
                @php($avatar = $owner->avatar?->file)
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
                {{-- Compose to another member is the friend localNav "Send Message" entry (rendered on
                     every page about them, gadgets or not), not a profile-content link. --}}
            </div>
        </div>
    @endsection
@endif
