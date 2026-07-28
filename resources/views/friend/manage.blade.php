@extends('layouts.classic')

@section('title', __('Pending %friend% requests'))

@section('content')
    {{-- Pending requests, which OpenPNE 3 served from confirmation/list, not friend/manage (that was
         the friend list with unlink links, folded into friend/list here) — so its ids do not carry
         over. The roster shape does: _partsManageList.php, a 76×76 photo over the name then one
         operation per cell. Those cells go unclassed, as connection/listSuccess.php's three do; the
         `delete` class sizes a lone operation cell, not a pair. --}}
    <x-classic.parts id="friend_manage_received" name="manageList" :title="__('Requests received')">
        @if ($received->isEmpty())
            <p>{{ __('No pending requests.') }}</p>
        @else
            <x-classic.pager :paginator="$received" />
            <div class="item"><table><tbody>
                @foreach ($received as $requester)
                    <tr>
                        <td class="photo"><a href="{{ route('member.profile.show', $requester) }}"><x-classic.image :file="$requester->avatar?->file" :size="76" :alt="$requester->name" /></a><br /><a href="{{ route('member.profile.show', $requester) }}">{{ $requester->name }}</a></td>
                        <td>
                            <form method="POST" action="{{ route('friend.accept') }}">
                                @csrf
                                <input type="hidden" name="requester_id" value="{{ $requester->getKey() }}">
                                <input type="submit" class="input_submit" value="{{ __('Accept') }}">
                            </form>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('friend.reject') }}">
                                @csrf
                                <input type="hidden" name="requester_id" value="{{ $requester->getKey() }}">
                                <input type="submit" class="input_submit" value="{{ __('Reject') }}">
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <x-classic.pager :paginator="$received" />
        @endif
    </x-classic.parts>

    <x-classic.parts id="friend_manage_sent" name="manageList" :title="__('Requests sent')">
        @if ($sent->isEmpty())
            <p>{{ __('No outgoing requests.') }}</p>
        @else
            <x-classic.pager :paginator="$sent" />
            <div class="item"><table><tbody>
                @foreach ($sent as $target)
                    <tr>
                        <td class="photo"><a href="{{ route('member.profile.show', $target) }}"><x-classic.image :file="$target->avatar?->file" :size="76" :alt="$target->name" /></a><br /><a href="{{ route('member.profile.show', $target) }}">{{ $target->name }}</a></td>
                        {{-- The sender cannot withdraw a request yet, so the operation cell stays the
                             empty one _partsManageList.php renders for an unavailable menu. --}}
                        <td>&nbsp;</td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <x-classic.pager :paginator="$sent" />
        @endif
    </x-classic.parts>
@endsection
