{{-- OpenPNE 3 localNav (default/localNav component): the `default` set on the viewer's own
     pages, the `friend` set (the subject's id-scoped Home/Diary/Friends) when viewing another
     member. The subject is recorded by Controller::markLocalNavSubject (OpenPNE 3 sf_nav_id).
     The admin-configurable Navigation data and the community context are deferred. --}}
@auth
    @php($navSubject = request()->attributes->get('localNavSubject'))
    @if ($navSubject)
        <ul class="friend">
            <li id="friend_home"><a href="{{ route('member.profile.show', $navSubject) }}">{{ __('Home') }}</a></li>
            <li id="friend_diary"><a href="{{ route('diary.list_member', $navSubject) }}">{{ __('%Diary%') }}</a></li>
            <li id="friend_friend"><a href="{{ route('friend.list', ['id' => $navSubject->getKey()]) }}">{{ __('%Friends%') }}</a></li>
        </ul>
    @else
        <ul class="default">
            <li id="default_home"><a href="{{ url('/') }}">{{ __('Home') }}</a></li>
            <li id="default_friend"><a href="{{ route('friend.list') }}">{{ __('%My_friends%') }}</a></li>
            <li id="default_diary"><a href="{{ route('diary.list_member') }}">{{ __('%Diary%') }}</a></li>
            <li id="default_profile"><a href="{{ route('member.profile.mine_compat') }}">{{ __('Profile') }}</a></li>
            <li id="default_editProfile"><a href="{{ route('member.profile.edit') }}">{{ __('Edit Profile') }}</a></li>
        </ul>
    @endif
@endauth
