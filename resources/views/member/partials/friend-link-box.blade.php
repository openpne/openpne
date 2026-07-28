{{-- The own-page notice and the friend-request entry, which OpenPNE 3 gave the same descriptionBox
     and the same id (profileSuccess.php, op_top) — kept, id/class included, for theme CSS
     overrides. $friendStatus is null for the viewer's own profile and for guests; blocked pairs
     never reach this page, and a guest gets neither box. --}}
@if ($isSelf ?? false)
    <x-classic.parts id="informationAboutThisIsYourProfilePage" name="descriptionBox">
        <div class="body">
            <p>{{ __('This is how other members see your page.') }}</p>
            <p>
                {{ __('To tell other members about your page, use this URL.') }}<br>
                {{ route('member.profile.show', $owner) }}
            </p>
            <p>
                {{ __('To change your profile, use the profile editor.') }}<br>
                <a href="{{ route('member.profile.edit') }}">{{ __('Edit Profile') }}</a>
            </p>
        </div>
    </x-classic.parts>
@elseif (in_array($friendStatus ?? null, ['none', 'sent', 'received'], true))
    <x-classic.parts id="informationAboutThisIsYourProfilePage" name="descriptionBox">
        <div class="body">
            @if ($friendStatus === 'none')
                <p>
                    {{ __('If :name is someone you know, send a %friend% request!', ['name' => $owner->name]) }}<br>
                    <a href="{{ route('friend.link.show', ['id' => $owner->getKey()]) }}">{{ __('Send a %friend% request') }}</a>
                </p>
            @elseif ($friendStatus === 'sent')
                <p><a href="{{ route('friend.manage') }}">{{ __('%Friend% request pending.') }}</a></p>
            @else
                <p><a href="{{ route('friend.manage') }}">{{ __(':name sent you a %friend% request.', ['name' => $owner->name]) }}</a></p>
            @endif
        </div>
    </x-classic.parts>
@endif
