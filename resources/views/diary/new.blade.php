@extends('layouts.classic')

@section('title', __('Write a %diary%'))

@section('content')
    <div class="dparts" id="diary_new">
        <h2 class="partsHeading">{{ __('Write a %diary%') }}</h2>
        <div class="parts">
            <form method="POST" action="{{ route('diary.store') }}">
                @csrf
                <div>
                    <label for="diary_title">{{ __('Title') }}</label>
                    <input type="text" id="diary_title" name="title" value="{{ old('title') }}" required>
                    @error('title')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="diary_body">{{ __('Body') }}</label>
                    <textarea id="diary_body" name="body" required>{{ old('body') }}</textarea>
                    @error('body')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="diary_visibility">{{ __('Visibility') }}</label>
                    <select id="diary_visibility" name="visibility">
                        <option value="1" {{ old('visibility', '1') == '1' ? 'selected' : '' }}>{{ __('All members') }}</option>
                        <option value="2" {{ old('visibility') == '2' ? 'selected' : '' }}>{{ __('%Friends% only') }}</option>
                        <option value="3" {{ old('visibility') == '3' ? 'selected' : '' }}>{{ __('Private') }}</option>
                    </select>
                    @error('visibility')<p class="error">{{ $message }}</p>@enderror
                </div>
                <button type="submit">{{ __('Post') }}</button>
            </form>
        </div>
    </div>
@endsection
