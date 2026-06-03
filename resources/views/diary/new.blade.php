@extends('layouts.classic')

@section('title', __('Write a %diary%'))

@section('content')
    <div class="dparts form" id="diary_new">
        <div class="partsHeading"><h3>{{ __('Write a %diary%') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('diary.store') }}">
                @csrf
                <table>
                    <tr>
                        <th><label for="diary_title">{{ __('Title') }}</label></th>
                        <td>
                            <input type="text" class="input_text" id="diary_title" name="title" value="{{ old('title') }}" required>
                            @error('title')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    <tr>
                        <th><label for="diary_body">{{ __('Body') }}</label></th>
                        <td>
                            <textarea id="diary_body" name="body" required>{{ old('body') }}</textarea>
                            @error('body')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    <tr>
                        <th><label for="diary_visibility">{{ __('Visibility') }}</label></th>
                        <td>
                            <select id="diary_visibility" name="visibility">
                                <option value="1" {{ old('visibility', '1') == '1' ? 'selected' : '' }}>{{ __('All members') }}</option>
                                <option value="2" {{ old('visibility') == '2' ? 'selected' : '' }}>{{ __('%Friends% only') }}</option>
                                <option value="3" {{ old('visibility') == '3' ? 'selected' : '' }}>{{ __('Private') }}</option>
                            </select>
                            @error('visibility')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Post') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
