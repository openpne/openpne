@extends('layouts.classic')

@use('App\Support\Surface')
@use('App\Features\Member\MemberConfigCategory')

@section('title', __('Settings'))

{{-- OpenPNE 3 member/config is paginated by ?category= (LayoutB): the category nav fills the
     sidemenu, the active category's form the center, or a "pick one" landing when none is selected. --}}
@section('sidemenu')
    <x-member.config-sidemenu :current="$category" />
@endsection

@section('content')
    @switch($category)
        @case(MemberConfigCategory::Diary)
            {{-- Diary default audience (member_preferences[diary_default_visibility]). --}}
            <div class="dparts form" id="member_config_diary">
                <div class="partsHeading"><h3>{{ __('Diary') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.diary') }}">
                        @csrf
                        <table>
                            <tr>
                                <th><label for="diary_default_visibility">{{ __('Default audience for new diaries') }}</label></th>
                                <td>
                                    <select id="diary_default_visibility" name="diary_default_visibility">
                                        @foreach ($diaryOptions as $option)
                                            <option value="{{ $option->value }}" @selected(old('diary_default_visibility', $diaryDefault->value) == $option->value)>{{ __($option->label()) }}</option>
                                        @endforeach
                                    </select>
                                    @error('diary_default_visibility')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        </table>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::PublicFlag)
            {{-- Age visibility (member_preferences[age_visibility]); no web-public choice — age is never shown to guests. --}}
            <div class="dparts form" id="member_config_age">
                <div class="partsHeading"><h3>{{ __('Age') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.age') }}">
                        @csrf
                        <table>
                            <tr>
                                <th><label for="age_visibility">{{ __('Who can see your age') }}</label></th>
                                <td>
                                    <select id="age_visibility" name="age_visibility">
                                        @foreach ($ageOptions as $option)
                                            <option value="{{ $option->value }}" @selected(old('age_visibility', $ageDefault->value) == $option->value)>{{ __($option->label()) }}</option>
                                        @endforeach
                                    </select>
                                    @error('age_visibility')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        </table>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::Language)
            {{-- Language: reuses the shared locale switch endpoint (durable members.locale write). --}}
            <div class="dparts form" id="member_config_language">
                <div class="partsHeading"><h3>{{ __('Language') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('locale.switch') }}">
                        @csrf
                        <table>
                            <tr>
                                <th><label for="locale">{{ __('Language') }}</label></th>
                                <td>
                                    <select id="locale" name="locale">
                                        <option value="ja" @selected($locale === 'ja')>日本語</option>
                                        <option value="en" @selected($locale === 'en')>English</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::General)
            {{-- Display surface (member_preferences[preferred_surface]); binary, preselected to the member's
                 current surface. Saving the current one is a no-op server-side, so it never pins. OpenPNE
                 4-native setting under OpenPNE 3's "general" catch-all category. --}}
            <div class="dparts form" id="member_config_surface">
                <div class="partsHeading"><h3>{{ __('Display') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.surface') }}">
                        @csrf
                        @foreach ([Surface::Classic, Surface::Modern] as $option)
                            <p>
                                <label>
                                    <input type="radio" name="preferred_surface" value="{{ $option->value }}" @checked($currentSurface === $option)>
                                    {{ __($option->label()) }} — {{ __($option->description()) }}
                                </label>
                            </p>
                        @endforeach
                        @error('preferred_surface')<p class="error">{{ $message }}</p>@enderror
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::Password)
            {{-- In-session password change: re-auth with the current password, new password entered twice. --}}
            <div class="dparts form" id="member_config_password">
                <div class="partsHeading"><h3>{{ __('Password') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.password') }}">
                        @csrf
                        <table>
                            <tr>
                                <th><label for="current_password">{{ __('Current password') }}</label></th>
                                <td>
                                    <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                                    @error('current_password')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                            <tr>
                                <th><label for="password">{{ __('New password') }}</label></th>
                                <td>
                                    <input type="password" id="password" name="password" autocomplete="new-password">
                                    @error('password')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                            <tr>
                                <th><label for="password_confirmation">{{ __('New password (confirm)') }}</label></th>
                                <td>
                                    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
                                </td>
                            </tr>
                        </table>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @default
            {{-- OpenPNE 3 landing (configInformation): no category selected, pick one from the nav.
                 id is an OpenPNE 4-side hook, not an OpenPNE 3 parity claim. --}}
            <div class="dparts" id="member_config_index">
                <div class="partsHeading"><h3>{{ __('Change Settings') }}</h3></div>
                <div class="parts">
                    <p>{{ __('Please select the item that wants to be set from the menu.') }}</p>
                </div>
            </div>
    @endswitch
@endsection
