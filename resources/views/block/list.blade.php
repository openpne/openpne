@extends('layouts.classic')

@section('title', __('Blocked members'))

@section('content')
    {{-- Block is OpenPNE 4-native (OpenPNE 3 kept access block as a member-config category), so these
         boxes carry no OpenPNE 3 kind or id to restore. --}}
    <x-classic.parts id="block_add" :title="__('Block a member')">
        <form method="GET" action="{{ route('block.add.show') }}">
            <label for="block_member_id">{{ __('Member ID') }}</label>
            <input type="number" class="input_text" name="id" id="block_member_id" min="1" required>
            <input type="submit" class="input_submit" value="{{ __('Block') }}">
        </form>
        <p class="help">{{ __('The member ID is the number at the end of the member page URL.') }}</p>
    </x-classic.parts>

    <x-classic.parts id="block_list" :title="__('Blocked members')">
        @if ($blocks->isEmpty())
            <p>{{ __('No blocked members.') }}</p>
        @else
            <x-classic.pager :paginator="$blocks" />
            <ul class="blockList">
                @foreach ($blocks as $blocked)
                    <li>
                        <span class="memberName">{{ $blocked->name }}</span>
                        <a href="{{ route('block.remove.show', $blocked) }}">{{ __('Unblock') }}</a>
                    </li>
                @endforeach
            </ul>
            <x-classic.pager :paginator="$blocks" />
        @endif
    </x-classic.parts>
@endsection
