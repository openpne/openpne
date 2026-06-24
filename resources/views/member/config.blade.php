@extends('layouts.classic')

@use('App\Support\Surface')

@section('title', __('Settings'))

@section('content')
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
                        <li><input type="submit" class="input_submit" value="{{ __('Change') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>

    {{-- Display surface (member_preferences[preferred_surface]); empty = follow the site default. --}}
    <div class="dparts form" id="member_config_surface">
        <div class="partsHeading"><h3>{{ __('Display') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('member.config.surface') }}">
                @csrf
                <table>
                    <tr>
                        <th><label for="preferred_surface">{{ __('Interface') }}</label></th>
                        <td>
                            <select id="preferred_surface" name="preferred_surface">
                                <option value="" @selected($preferredSurface === null)>{{ __('Site default') }}</option>
                                <option value="{{ Surface::Classic->value }}" @selected($preferredSurface === Surface::Classic)>{{ __('Classic') }}</option>
                                <option value="{{ Surface::Modern->value }}" @selected($preferredSurface === Surface::Modern)>{{ __('Modern') }}</option>
                            </select>
                            @error('preferred_surface')<p class="error">{{ $message }}</p>@enderror
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
@endsection
