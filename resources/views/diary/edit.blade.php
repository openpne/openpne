@extends('layouts.classic')

@section('title', __('Edit %diary%'))

@section('sidemenu')
    <x-diary.sidemenu :member="$diary->member" />
@endsection

@section('content')
    {{-- OpenPNE 3 shares one _form partial (and so one parts id) between new and edit. --}}
    <x-classic.parts id="diaryForm" name="form" :title="__('Edit %diary%')">
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
                @include('compose._format-toggle', ['format' => $diary->format])
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
    </x-classic.parts>

    {{-- OpenPNE 3 editSuccess.php hand-writes this box below the form: a GET form to the delete
         confirm page, so the entry point sits where the entry is being edited. --}}
    <x-classic.parts id="formDiaryDelete" name="box" :title="__('Delete this %diary%')">
        <div class="block">
            <form method="GET" action="{{ route('diary.delete.show', $diary) }}">
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </x-classic.parts>
@endsection
