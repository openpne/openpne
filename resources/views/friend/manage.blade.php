@extends('layouts.classic')

@section('title', __('%my_friend% Setting'))

@section('content')
    @if ($friends->isEmpty())
        {{-- manageError.php: the box saying so, then the history-back line. --}}
        <x-classic.parts id="manageFriendWarning" name="box" :title="__('%friend% List')">
            <div class="body">{{ __("You don't have any %friend%.") }}</div>
        </x-classic.parts>
        <x-classic.history-back :fallback="route('friend.list')" />
    @else
        {{-- manageSuccess.php: the member's own roster as _partsManageList.php draws it — the pager
             above and below, a 76×76 photo over the name, and the one operation OpenPNE 3 offered
             here, its cell carrying the menu's `delete` class. --}}
        <x-classic.parts id="manageList" name="manageList" :title="__('%my_friend% Setting')">
            <x-classic.pager :paginator="$friends" />
            <div class="item"><table><tbody>
                @foreach ($friends as $friend)
                    <tr>
                        <td class="photo"><a href="{{ route('member.profile.show', $friend) }}"><x-classic.image :file="$friend->avatar?->file" :size="76" :alt="$friend->name" /></a><br /><a href="{{ route('member.profile.show', $friend) }}">{{ $friend->name }}</a></td>
                        <td class="delete"><a href="{{ route('friend.unlink.show', $friend) }}">{{ __('Delete from %my_friend%.') }}</a></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <x-classic.pager :paginator="$friends" />
        </x-classic.parts>
    @endif
@endsection
