@extends('layouts.classic')

@php($community = $community ?? null)

@section('title', __('%Post_activity%'))

@section('content')
    {{-- OpenPNE 3 inlines the compose box in the feed itself, so there is no OpenPNE 3 form box to
         reproduce: the standalone page takes the form kind and keeps its OpenPNE 4 id. --}}
    <x-classic.parts id="timeline_new" name="form" :title="$community ? __(':community %activity%', ['community' => $community->name]) : __('%Post_activity%')">
        <form method="POST" action="{{ $community ? route('community.timeline.store', ['community' => $community]) : route('timeline.store') }}" enctype="multipart/form-data"
              data-timeline-mention data-mention-candidates-url="{{ $community ? route('timeline.mention_candidates', ['community' => $community]) : route('timeline.mention_candidates') }}" data-mention-no-image-url="{{ asset('images/no_image.gif') }}" data-mention-label="{{ __('Mention candidates') }}">
            @csrf
            @if ($community)
                <input type="hidden" name="from" value="new">
            @endif
            @include('timeline._mention-draft')
            <table>
                <tr>
                    <th><label for="timeline_body">{{ __('Body') }}</label></th>
                    <td>
                        <textarea id="timeline_body" name="body" required>{{ old('body') }}</textarea>
                        @error('body')<p class="error">{{ $message }}</p>@enderror
                    </td>
                </tr>
                {{-- A community post's audience is the community, so there is nothing to choose. --}}
                @unless ($community)
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
                @endunless
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
        @include('timeline._mention-picker')
    </x-classic.parts>
@endsection
