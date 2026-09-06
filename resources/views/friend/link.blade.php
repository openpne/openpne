@extends('layouts.classic')

@section('title', __('Add %my_friends%'))

@section('content')
    {{-- OpenPNE 3 linkInput.php: a form parts whose firstRow names the target (photo + %nickname%)
         and whose FriendLinkForm has no field of its own. --}}
    <x-classic.parts id="friendLink" name="form" :title="__('Add %my_friends%')">
        <form method="POST" action="{{ route('friend.link') }}">
            @csrf
            <input type="hidden" name="target_id" value="{{ $target->getKey() }}">
            <table>
                <tr>
                    <th>{{ __('Photo') }}</th>
                    <td><a href="{{ route('member.profile.show', $target) }}"><x-classic.image :file="$target->avatar?->file" :size="76" :alt="$target->name" /></a></td>
                </tr>
                <tr>
                    <th>{{ __('%Nickname%') }}</th>
                    <td><a href="{{ route('member.profile.show', $target) }}">{{ $target->name }}</a></td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Send request') }}"></li>
                    <li><a href="{{ route('friend.list') }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
