@extends('layouts.classic')

@section('title', __('%Post_activity%'))

@section('content')
    <div class="dparts form" id="timeline_new">
        <div class="partsHeading"><h3>{{ __('%Post_activity%') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('timeline.store') }}" enctype="multipart/form-data">
                @csrf
                <table>
                    <tr>
                        <th><label for="timeline_body">{{ __('Body') }}</label></th>
                        <td>
                            <textarea id="timeline_body" name="body" maxlength="140" required>{{ old('body') }}</textarea>
                            @error('body')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    <tr>
                        <th><label for="timeline_visibility">{{ __('Visibility') }}</label></th>
                        <td>
                            <select id="timeline_visibility" name="visibility">
                                @foreach ($visibilityOptions as $option)
                                    <option value="{{ $option->value }}" @selected(old('visibility', $defaultVisibility->value) == $option->value)>{{ __($option->label()) }}</option>
                                @endforeach
                            </select>
                            @error('visibility')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    <tr>
                        <th><label for="timeline_image">{{ __('Image') }}</label></th>
                        <td>
                            <input type="file" id="timeline_image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
                            @error('image')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('%Post_activity%') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
