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
            <x-classic.required-notice />
            <table>
                <tr>
                    <th><label for="diary_title">{{ __('Title') }} <x-classic.required-mark /></label></th>
                    <td>
                        <input type="text" class="input_text" id="diary_title" name="title" value="{{ old('title', $diary->title) }}" required>
                        @error('title')<p class="error">{{ $message }}</p>@enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="diary_body">{{ __('Body') }} <x-classic.required-mark /></label></th>
                    <td>
                        <textarea id="diary_body" name="body" required>{{ old('body', $diary->body) }}</textarea>
                        @error('body')<p class="error">{{ $message }}</p>@enderror
                    </td>
                </tr>
                @include('compose._format-toggle', ['format' => $diary->format])
                <tr>
                    <th><label>{{ __('Visibility') }} <x-classic.required-mark /></label></th>
                    <td>
                        <ul class="radio_list">
                            @foreach ($visibilityOptions as $option)
                                <li><input type="radio" name="visibility" value="{{ $option->value }}" id="diary_public_flag_{{ $option->openPne3PublicFlag() }}" class="input_radio" @checked(old('visibility', $diary->visibility->value) == $option->value)> <label for="diary_public_flag_{{ $option->openPne3PublicFlag() }}">{{ __($option->label()) }}</label></li>
                            @endforeach
                        </ul>
                        @error('visibility')<p class="error">{{ $message }}</p>@enderror
                    </td>
                </tr>
                <x-classic.photo-rows name="diary" :images="$diary->images" />
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
