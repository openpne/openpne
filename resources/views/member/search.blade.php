@extends('layouts.classic')

@section('title', __('Member search'))

@section('content')
    <x-classic.parts id="searchMember" name="form" :title="__('Member search')">
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
                @if ($showAge)
                    <tr>
                        <th><label for="age_min">{{ __('Age') }}</label></th>
                        <td>
                            <input type="number" min="0" class="input_text" id="age_min" name="age[min]" value="{{ $ageRange['min'] ?? '' }}">
                            <span>–</span>
                            <input type="number" min="0" class="input_text" name="age[max]" value="{{ $ageRange['max'] ?? '' }}">
                        </td>
                    </tr>
                @endif
            </table>

            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Search') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>

    @if ($members->isEmpty())
        {{-- OpenPNE 3 searchSuccess.php swaps the result list for a plain box once the pager is empty. --}}
        <x-classic.parts id="searchMemberResult" name="box" :title="__('Search Results')">
            <div class="body">{{ __('No members found.') }}</div>
        </x-classic.parts>
    @else
        {{-- OpenPNE 3 closed each band with a last-login row; OpenPNE 4 stores no last-login time
             (MemberRouteParity screen gap), so a member with no visible self-introduction shows the
             nickname alone. The nickname prints as text: OpenPNE 3 passed use_op_link_to_member here
             but _partsSearchResultList.php never read it.

             Inline PHP rather than a block: Blade pairs the first raw-PHP opener in a file with the
             first terminator, so a block here would swallow the inline PHP in the form above. --}}
        @php($results = $members->map(fn ($member) => [
            'url' => route('member.profile.show', $member),
            'file' => $member->avatar?->file,
            'name' => $member->name,
            'isAi' => $member->isAiAccount(),
            'rows' => array_values(array_filter([
                ['caption' => __('%Nickname%'), 'value' => $member->name],
                isset($introductions[$member->getKey()])
                    ? ['caption' => __('Self Introduction'), 'value' => $introductions[$member->getKey()]]
                    : null,
            ])),
        ])->all())
        {{-- searchCommunityResult is what OpenPNE 3 named the *member* result list: a copy-paste from
             the community search it never fixed, and skins target it, so it is restored verbatim. --}}
        <x-classic.parts id="searchCommunityResult" name="searchResultList" :title="__('Search Results')">
            <x-classic.search-result-list :items="$results" :paginator="$members" />
        </x-classic.parts>
    @endif
@endsection
