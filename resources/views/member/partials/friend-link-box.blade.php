{{-- OpenPNE 3 member/profile op_top slot: the friend-request entry in its descriptionBox parts
     wrapper (id/class preserved for theme CSS overrides). $friendStatus is null for the viewer's
     own profile and for guests; blocked pairs never reach this page. --}}
@if (in_array($friendStatus ?? null, ['none', 'sent', 'received'], true))
    <div id="informationAboutThisIsYourProfilePage" class="dparts descriptionBox">
        <div class="parts">
            <div class="body">
                @if ($friendStatus === 'none')
                    <p>
                        {{ __('If :name is a friend of yours, send a %friend% request!', ['name' => $owner->name]) }}<br>
                        <a href="{{ route('friend.link.show', ['id' => $owner->getKey()]) }}">{{ __('Send a %friend% request') }}</a>
                    </p>
                @elseif ($friendStatus === 'sent')
                    <p><a href="{{ route('friend.manage') }}">{{ __('%Friend% request pending.') }}</a></p>
                @else
                    <p><a href="{{ route('friend.manage') }}">{{ __(':name sent you a %friend% request.', ['name' => $owner->name]) }}</a></p>
                @endif
            </div>
        </div>
    </div>
@endif
