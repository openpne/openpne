@extends('layouts.classic')

@section('title', __('Edit %diary%'))

@section('content')
    <div class="dparts form" id="diary_edit">
        <div class="partsHeading"><h3>{{ __('Edit %diary%') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('diary.update', $diary) }}">
                @csrf
                <table>
                    <tr>
                        <th><label for="diary_title">{{ __('Title') }}</label></th>
                        <td>
                            <input type="text" class="input_text" id="diary_title" name="title" value="{{ old('title', $diary->title) }}" required>
                            @error('title')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    <tr>
                        <th><label for="diary_body">{{ __('Body') }}</label></th>
                        <td>
                            <textarea id="diary_body" name="body" required>{{ old('body', $diary->body) }}</textarea>
                            @error('body')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    <tr>
                        <th><label for="diary_visibility">{{ __('Visibility') }}</label></th>
                        <td>
                            <select id="diary_visibility" name="visibility">
                                <option value="1" {{ old('visibility', $diary->visibility->value) == 1 ? 'selected' : '' }}>{{ __('All members') }}</option>
                                <option value="2" {{ old('visibility', $diary->visibility->value) == 2 ? 'selected' : '' }}>{{ __('%Friends% only') }}</option>
                                <option value="3" {{ old('visibility', $diary->visibility->value) == 3 ? 'selected' : '' }}>{{ __('Private') }}</option>
                            </select>
                            @error('visibility')<p class="error">{{ $message }}</p>@enderror
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
