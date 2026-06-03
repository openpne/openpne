@extends('layouts.classic')

@section('title', __('Edit Profile'))

@section('content')
    <div class="dparts form" id="member_editProfile">
        <div class="partsHeading"><h3>{{ __('Edit Profile') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('member.profile.update') }}">
                @csrf
                <table>
                    <tr>
                        <th><label for="member_name">{{ __('%nickname%') }}</label></th>
                        <td>
                            <input type="text" class="input_text" id="member_name" name="name" value="{{ old('name', $member->name) }}" maxlength="255" required>
                            @error('name')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>

                    @foreach ($fields as $field)
                        @php
                            $profile = $field->profile;
                            $id = $profile->getKey();
                            $valueKey = "profile.{$id}";
                            $current = old($valueKey, $field->value);
                            $fieldId = 'profile_'.$profile->name.'_value';
                        @endphp
                        <tr>
                            <th><label for="{{ $fieldId }}">{{ $profile->getCaption($lang) }}@if ($profile->is_required)<span class="required" aria-label="required">*</span>@endif</label></th>
                            <td>
                                {{-- OpenPNE 3 floats the field (.input) left and the visibility (.publicFlag) right. --}}
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
                                        @foreach ($profile->choices($lang) as $choice)
                                            <label><input type="radio" name="profile[{{ $id }}]" value="{{ $choice['id'] }}" @checked((string) $current === (string) $choice['id'])> {{ $choice['caption'] }}</label>
                                        @endforeach
                                        @break

                                    @case('checkbox')
                                        @php $selected = array_map('strval', (array) $current); @endphp
                                        @foreach ($profile->choices($lang) as $choice)
                                            <label><input type="checkbox" name="profile[{{ $id }}][]" value="{{ $choice['id'] }}" @checked(in_array((string) $choice['id'], $selected, true))> {{ $choice['caption'] }}</label>
                                        @endforeach
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
                                        <select name="visibility[{{ $id }}]">
                                            @foreach ($profile->visibilityOptions() as $option)
                                                <option value="{{ $option->value }}" @selected($currentVisibility === $option->value)>{{ __($option->label()) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif

                                @if ($profile->getInfo($lang))
                                    <p class="help">{{ $profile->getInfo($lang) }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>

                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Update') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
