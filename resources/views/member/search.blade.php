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
                                @switch($profile->form_type)
                                    @case('select')
                                    @case('radio')
                                        <select name="profile[{{ $id }}]">
                                            <option value="">{{ __('Any') }}</option>
                                            @foreach ($profile->choices($lang) as $choice)
                                                <option value="{{ $choice['id'] }}" @selected((string) $current === (string) $choice['id'])>{{ $choice['caption'] }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @case('checkbox')
                                        @php $selected = array_map('strval', (array) $current); @endphp
                                        @foreach ($profile->choices($lang) as $choice)
                                            <label><input type="checkbox" name="profile[{{ $id }}][]" value="{{ $choice['id'] }}" @checked(in_array((string) $choice['id'], $selected, true))> {{ $choice['caption'] }}</label>
                                        @endforeach
                                        @break

                                    @case('date')
                                        <input type="date" class="input_text" name="date[{{ $id }}][from]" value="{{ $range['from'] ?? '' }}">
                                        <span>–</span>
                                        <input type="date" class="input_text" name="date[{{ $id }}][to]" value="{{ $range['to'] ?? '' }}">
                                        @break

                                    @case('country_select')
                                        <select name="profile[{{ $id }}]">
                                            <option value="">{{ __('Any') }}</option>
                                            @foreach (app(\App\Services\CountryListService::class)->getOptions($lang) as $code => $countryName)
                                                <option value="{{ $code }}" @selected((string) $current === $code)>{{ $countryName }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @case('region_select')
                                        @php $regionOptions = app(\App\Services\RegionListService::class)->getOptions($profile->value_type, $lang); @endphp
                                        <select name="profile[{{ $id }}]">
                                            <option value="">{{ __('Any') }}</option>
                                            @if (($profile->value_type ?: 'string') === 'string')
                                                @foreach ($regionOptions as $countryName => $regions)
                                                    <optgroup label="{{ $countryName }}">
                                                        @foreach ($regions as $value => $regionLabel)
                                                            <option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $regionLabel }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            @else
                                                @foreach ($regionOptions as $value => $regionLabel)
                                                    <option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $regionLabel }}</option>
                                                @endforeach
                                            @endif
                                        </select>
                                        @break

                                    @default
                                        <input type="text" class="input_text" name="profile[{{ $id }}]" value="{{ is_array($current) ? '' : $current }}">
                                @endswitch
                            </td>
                        </tr>
                    @endforeach
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
        <div class="partsHeading"><h3>{{ __('Results') }}</h3></div>
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
