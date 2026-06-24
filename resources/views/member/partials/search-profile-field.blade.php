{{-- One member-search input, by profile form_type. Inherits $profile, $id, $current, $range, $lang
     from the search form loop. The birthday preset is handled separately (month/day) by the caller. --}}
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
