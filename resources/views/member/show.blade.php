@extends('layouts.classic')

@section('title', $owner->name)

@php($zones = $zones ?? [])
@php($hasGadgets = collect($zones)->flatten(1)->isNotEmpty())

@if ($hasGadgets)
    @include('partials.gadget-sections', ['zones' => $zones, 'contentTop' => 'member.partials.friend-link-box'])
@else
    @section('content')
        @include('member.partials.friend-link-box')
        {{-- No profile gadgets configured: the fixed profile box (avatar + values + own-page links).
             OpenPNE 3 always rendered this page from gadgets, so there is no OpenPNE 3 kind or id to
             restore here. --}}
        <x-classic.parts id="member_profile" :title="$owner->name">
            @php($avatar = $owner->avatar?->file)
            <p><x-classic.image :file="$avatar" :size="120" :alt="$owner->name" /></p>

            @if ($fields->isEmpty() && $age === null)
                <p>{{ __('No profile to show.') }}</p>
            @else
                <table class="listBox">
                    @if ($age !== null)
                        <tr>
                            <th>{{ __('Age') }}</th>
                            <td>{{ __(':age years old', ['age' => $age]) }}</td>
                        </tr>
                    @endif
                    @foreach ($fields as $field)
                        <tr>
                            <th>{{ $field->profile->getCaption($lang) }}</th>
                            <td><x-user-text :value="$field->display($lang)" /></td>
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
        </x-classic.parts>
    @endsection
@endif
