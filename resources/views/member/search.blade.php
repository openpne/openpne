@extends('layouts.classic')

@section('title', __('Member search'))

@section('content')
    <div class="dparts form" id="member_search">
        <div class="partsHeading"><h3>{{ __('Member search') }}</h3></div>
        <div class="parts">
            <form method="GET" action="{{ route('member.search') }}">
                <table>
                    <tr>
                        <th><label for="search_name">{{ __('%nickname%') }}</label></th>
                        <td><input type="text" class="input_text" id="search_name" name="name" value="{{ $name }}"></td>
                    </tr>

                    @foreach ($profiles as $profile)
                        @php
                            $id = $profile->getKey();
                            $current = $filters[$id] ?? null;
                            $range = $dateRanges[$id] ?? [];
                        @endphp
                        <tr>
                            <th><label>{{ $profile->getCaption($lang) }}</label></th>
                            <td>
                                @if ($profile->name === $birthdayName)
                                    @php($md = $monthDayRanges[$id] ?? [])
                                    {{-- Month/day only: the birth year (= age) is searched via the Age field below. --}}
                                    @foreach (['from', 'to'] as $bound)
                                        <select name="monthday[{{ $id }}][{{ $bound }}_month]">
                                            <option value="">{{ __('Month') }}</option>
                                            @for ($m = 1; $m <= 12; $m++)
                                                <option value="{{ $m }}" @selected((string) ($md[$bound.'_month'] ?? '') === (string) $m)>{{ $m }}</option>
                                            @endfor
                                        </select>
                                        <select name="monthday[{{ $id }}][{{ $bound }}_day]">
                                            <option value="">{{ __('Day') }}</option>
                                            @for ($d = 1; $d <= 31; $d++)
                                                <option value="{{ $d }}" @selected((string) ($md[$bound.'_day'] ?? '') === (string) $d)>{{ $d }}</option>
                                            @endfor
                                        </select>
                                        @if ($bound === 'from')<span>–</span>@endif
                                    @endforeach
                                @else
                                    @include('member.partials.search-profile-field')
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    {{-- Derived age, gated by AgeVisibility (separate from the birthday field above). --}}
                    <tr>
                        <th><label for="age_min">{{ __('Age') }}</label></th>
                        <td>
                            <input type="number" min="0" class="input_text" id="age_min" name="age[min]" value="{{ $ageRange['min'] ?? '' }}">
                            <span>–</span>
                            <input type="number" min="0" class="input_text" name="age[max]" value="{{ $ageRange['max'] ?? '' }}">
                        </td>
                    </tr>
                </table>

                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Search') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>

    <div class="dparts" id="member_search_result">
        <div class="partsHeading"><h3>{{ __('Search Results') }}</h3></div>
        <div class="parts">
            @if ($members->isEmpty())
                <p>{{ __('No members found.') }}</p>
            @else
                <ul class="memberList">
                    @foreach ($members as $member)
                        @php($avatar = $member->avatar?->file)
                        <li>
                            <a href="{{ route('member.profile.show', $member) }}">
                                @if ($avatar)
                                    <img src="{{ $avatar->thumbnailUrl(76, 76, square: true) }}" alt="{{ $member->name }}">
                                @endif
                                {{ $member->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>

                {{ $members->links() }}
            @endif
        </div>
    </div>
@endsection
