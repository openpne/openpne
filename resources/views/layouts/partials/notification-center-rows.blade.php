{{-- The panel's rows, fetched on first open. OpenPNE 3's `#notificationCenterListTemplate`, moved
     to the server: the sentence in each row is already resolved in PHP, and a second copy of that
     wording in the browser is a copy that can drift.

     A %friend% row is the one OpenPNE 3 did not let you click through — it asks for the decision
     right here — so it carries no row link and everything else does. --}}
@use('App\Features\Notifications\NotificationCenterCategory')
@forelse ($rows as $row)
    <div class="push{{ $row->read ? ' isread' : '' }}{{ $row->awaitingDecision ? '' : ' nclink' }}"
         data-notify-id="{{ $row->id }}">
        <div class="push_icon">
            <x-classic.image :file="$row->actorAvatar" :size="48" :alt="$row->actorName ?? ''" />
        </div>
        <div class="push_content">
            @if ($row->awaitingDecision)
                {{ __('%Friend% request from') }}&nbsp;<a href="{{ route('member.profile.show', $row->actorId) }}">{{ $row->actorName }}</a><br>
                {{ __('Do you accept %friend% link request?') }}
                {{-- OpenPNE 3's own three parts: the two buttons, the spinner it showed while the
                     answer was in flight, and where the outcome lands. The outcome is a status so a
                     reader hears it without the panel stealing focus, and is reachable so the
                     keyboard has somewhere to be once the buttons go. --}}
                <div class="push_yesno" aria-busy="false">
                    <button type="button" class="friend-accept"
                            data-accept-url="{{ route('notifications.center.friendAccept', $row->id) }}">YES</button>
                    <button type="button" class="friend-reject"
                            data-reject-url="{{ route('notifications.center.friendReject', $row->id) }}">NO</button>
                    <div class="ncfriendloading"><img src="{{ asset('images/ajax-loader.gif') }}" alt=""></div>
                    <div class="ncfriendresultmessage" role="status" tabindex="-1"></div>
                </div>
            @else
                {{-- Opening marks it read and then lands on what it is about, so it submits rather
                     than links — the same action the Classic feed's rows post to. --}}
                <form method="POST" action="{{ route('notifications.open', $row->id) }}">
                    @csrf
                    <button type="submit" class="notificationCenterRowLink">{{ $row->label }}</button>
                </form>
            @endif
        </div>
    </div>
@empty
@endforelse
