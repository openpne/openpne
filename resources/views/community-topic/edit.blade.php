@extends('layouts.classic')

@section('title', __('Edit %topic%'))

@section('content')
    <div class="dparts form" id="communityTopic_edit">
        <div class="partsHeading"><h3>{{ __('Edit %topic%') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('communityTopic.update', $topic) }}" enctype="multipart/form-data">
                @csrf
                <table>
                    @include('community-topic._fields', ['name' => $topic->name, 'body' => $topic->body])
                    @if ($topic->images->isNotEmpty())
                        <tr>
                            <th>{{ __('Current images') }}</th>
                            <td>
                                <ul class="photo">
                                    @foreach ($topic->images as $image)
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
                        <li><a href="{{ route('communityTopic.show', $topic) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
