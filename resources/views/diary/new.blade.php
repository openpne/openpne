@extends('layouts.classic')

@section('title', __('Write a %diary%'))

@section('sidemenu')
    <x-diary.sidemenu :member="auth()->user()" />
@endsection

@section('content')
    <x-classic.parts id="diaryForm" name="form" :title="__('Write a %diary%')">
        <form method="POST" action="{{ route('diary.store') }}" enctype="multipart/form-data">
            @csrf
            <x-classic.required-notice />
            <table>
                <tr>
                    <th><label for="diary_title">{{ __('Title') }} <x-classic.required-mark /></label></th>
                    <td>
                        <input type="text" class="input_text" id="diary_title" name="title" value="{{ old('title') }}" required>
                        @error('title')<p class="error">{{ $message }}</p>@enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="diary_body">{{ __('Body') }} <x-classic.required-mark /></label></th>
                    <td>
                        <textarea id="diary_body" name="body" required>{{ old('body') }}</textarea>
                        @error('body')<p class="error">{{ $message }}</p>@enderror
                    </td>
                </tr>
                @include('compose._format-toggle', ['format' => null])
                <tr>
                    <th><label>{{ __('Visibility') }} <x-classic.required-mark /></label></th>
                    <td>
                        <ul class="radio_list">
                            @foreach ($visibilityOptions as $option)
                                <li><input type="radio" name="visibility" value="{{ $option->value }}" id="diary_public_flag_{{ $option->openPne3PublicFlag() }}" class="input_radio" @checked(old('visibility', $defaultVisibility->value) == $option->value)> <label for="diary_public_flag_{{ $option->openPne3PublicFlag() }}">{{ __($option->label()) }}</label></li>
                            @endforeach
                        </ul>
                        @error('visibility')<p class="error">{{ $message }}</p>@enderror
                    </td>
                </tr>
                <x-classic.photo-rows name="diary" />
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Post') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
