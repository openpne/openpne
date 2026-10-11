{{-- Configurable profile fields as form rows, shared by profile-edit and registration so the two
     never drift. Expects $fields (Collection<EditableField>) and $lang. Each row repopulates from
     old() so it survives a validation redirect. --}}
@foreach ($fields as $field)
    @php
        $profile = $field->profile;
        $id = $profile->getKey();
        $valueKey = "profile.{$id}";
        $current = old($valueKey, $field->value);
        $fieldId = 'profile_'.$profile->name.'_value';
        $widgetClass = match ($profile->form_type) {
            'checkbox' => 'input_checkbox',
            'radio' => 'input_radio',
            'textarea', 'select', 'date', 'country_select', 'region_select' => null,
            default => 'input_text',
        };
    @endphp
    <tr>
        <th><label for="{{ $fieldId }}">{{ $profile->getCaption($lang) }}@if ($profile->is_required) <x-classic.required-mark />@endif</label></th>
        <td>
            {{-- The skin floats the field (.input) left and the visibility (.publicFlag) right. --}}
            @if ($profile->is_edit_public_flag)<div class="input">@endif

            @switch($profile->form_type)
                @case('textarea')
                    <textarea id="{{ $fieldId }}" name="profile[{{ $id }}]" rows="5">{{ $current }}</textarea>
                    @break

                @case('select')
                    <select id="{{ $fieldId }}" name="profile[{{ $id }}]">
                        <option value="">{{ __('Please Select') }}</option>
                        @foreach ($profile->choices($lang) as $choice)
                            <option value="{{ $choice['id'] }}" @selected((string) $current === (string) $choice['id'])>{{ $choice['caption'] }}</option>
                        @endforeach
                    </select>
                    @break

                @case('radio')
                    <ul class="radio_list">
                        @foreach ($profile->choices($lang) as $choice)
                            <li><input type="radio" name="profile[{{ $id }}]" value="{{ $choice['id'] }}" id="{{ $fieldId }}_{{ $choice['id'] }}" class="input_radio" @checked((string) $current === (string) $choice['id'])> <label for="{{ $fieldId }}_{{ $choice['id'] }}">{{ $choice['caption'] }}</label></li>
                        @endforeach
                    </ul>
                    @break

                @case('checkbox')
                    @php $selected = array_map('strval', (array) $current); @endphp
                    <ul class="checkbox_list">
                        @foreach ($profile->choices($lang) as $choice)
                            <li><input type="checkbox" name="profile[{{ $id }}][]" value="{{ $choice['id'] }}" id="{{ $fieldId }}_{{ $choice['id'] }}" class="input_checkbox" @checked(in_array((string) $choice['id'], $selected, true))> <label for="{{ $fieldId }}_{{ $choice['id'] }}">{{ $choice['caption'] }}</label></li>
                        @endforeach
                    </ul>
                    @break

                @case('date')
                    <input type="date" class="input_text" id="{{ $fieldId }}" name="profile[{{ $id }}]" value="{{ $current }}">
                    @break

                @case('country_select')
                    <select id="{{ $fieldId }}" name="profile[{{ $id }}]">
                        <option value="">{{ __('Please Select') }}</option>
                        @foreach (app(\App\Services\CountryListService::class)->getOptions($lang) as $code => $countryName)
                            <option value="{{ $code }}" @selected((string) $current === $code)>{{ $countryName }}</option>
                        @endforeach
                    </select>
                    @break

                @case('region_select')
                    @php $regionOptions = app(\App\Services\RegionListService::class)->getOptions($profile->value_type, $lang); @endphp
                    <select id="{{ $fieldId }}" name="profile[{{ $id }}]">
                        <option value="">{{ __('Please Select') }}</option>
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
                    <input type="text" class="input_text" id="{{ $fieldId }}" name="profile[{{ $id }}]" value="{{ $current }}">
            @endswitch

            @error($valueKey)<p class="error">{{ $message }}</p>@enderror

            @if ($profile->is_edit_public_flag)
                </div>
                @php $currentVisibility = (int) old("visibility.{$id}", $field->visibility->value); @endphp
                <div class="publicFlag">
                    {{-- _partsForm.php hands the widget's class to the whole profile widget, so the audience select wears it too. --}}
                    <select id="profile_{{ $profile->name }}_public_flag" name="visibility[{{ $id }}]"@if ($widgetClass) class="{{ $widgetClass }}"@endif>
                        @foreach ($profile->visibilityOptions($field->visibility) as $option)
                            <option value="{{ $option->value }}" @selected($currentVisibility === $option->value)>{{ __($option->label()) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($profile->getInfo($lang))
                <div class="help">{{ $profile->getInfo($lang) }}</div>
            @endif
        </td>
    </tr>
@endforeach
