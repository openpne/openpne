@extends('layouts.classic')

@section('title', __('Edit event'))

@section('content')
    <div class="dparts form" id="communityEvent_edit">
        <div class="partsHeading"><h3>{{ __('Edit event') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('communityEvent.update', $event) }}" enctype="multipart/form-data">
                @csrf
                <table>
                    @include('community-event._fields', ['event' => $event])
                    @if ($event->images->isNotEmpty())
                        <tr>
                            <th>{{ __('Current images') }}</th>
                            <td>
                                <ul class="photo">
                                    @foreach ($event->images as $image)
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
                    @include('community-event._image_fields')
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                        <li><a href="{{ route('communityEvent.show', $event) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
