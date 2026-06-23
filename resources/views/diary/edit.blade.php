@extends('layouts.classic')

@section('title', __('Edit %diary%'))

@section('sidemenu')
    <x-diary.sidemenu :member="$diary->member" />
@endsection

@section('content')
    <div class="dparts form" id="diary_edit">
        <div class="partsHeading"><h3>{{ __('Edit %diary%') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('diary.update', $diary) }}" enctype="multipart/form-data">
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
                                @foreach ($visibilityOptions as $option)
                                    <option value="{{ $option->value }}" @selected(old('visibility', $diary->visibility->value) == $option->value)>{{ __($option->label()) }}</option>
                                @endforeach
                            </select>
                            @error('visibility')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    @if ($diary->images->isNotEmpty())
                        <tr>
                            <th>{{ __('Current images') }}</th>
                            <td>
                                <ul class="photo">
                                    @foreach ($diary->images as $image)
                                        @continue($image->file === null)
                                        <li>
                                            <img src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt="">
                                            <label><input type="checkbox" name="remove_images[]" value="{{ $image->id }}"> {{ __('Delete') }}</label>
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endif
                    @include('community-topic._image_fields')
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
