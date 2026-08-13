{{-- The home cautions: OpenPNE 3's `information` parts customizations on member/homeSuccess.
     It hung every one of them off the single informationBox body, so one box wraps the whole set,
     and it ordered them by sorting the customize attribute names — the order they are written in
     here. Each line keeps its own OpenPNE 3 partial's markup, differences included. Renders
     nothing when there is none. --}}
@php
    $adminTransferGroups = $adminTransferGroups ?? [];
    $friendRequests = $unread['friendRequests'] ?? 0;
    $unreadMessages = $unread['unreadMessages'] ?? 0;
@endphp
@if (count($adminTransferGroups) || $friendRequests || $unreadMessages)
    <x-classic.parts name="informationBox">
        <div class="body">
            {{-- One line per community awaiting the viewer's admin-transfer decision, each linking
                 to that community's home where the accept/reject banner lives (OpenPNE 3
                 _cautionAboutChangeAdminRequest, which pointed at the removed confirmation center). --}}
            @foreach ($adminTransferGroups as $nominatingGroup)
                <p class="caution">
                    {{ __('The administrator of :name asks you to take over the administration.', ['name' => $nominatingGroup->name]) }}
                    <a href="{{ route('group.show', $nominatingGroup) }}">{{ $nominatingGroup->name }}</a>
                </p>
            @endforeach

            {{-- _cautionAboutFriendPre, sent to the pending-request screen the confirmation center
                 became. --}}
            @if ($friendRequests)
                <p class="caution">
                    {{ __("You've gotten :count %friend% requests", ['count' => $friendRequests]) }}
                    <a href="{{ route('friend.requests') }}">{{ __('Check requests') }}</a>
                </p>
            @endif

            {{-- opMessagePlugin _unreadMessage. --}}
            @if ($unreadMessages)
                <ul><li>★<span class="caution">{{ __('There are new :count messages!', ['count' => $unreadMessages]) }}</span> <a href="{{ route('message.index') }}"><strong>{{ __('Read messages') }}</strong></a></li></ul>
            @endif
        </div>
    </x-classic.parts>
@endif
