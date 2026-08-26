@extends('layouts.classic')

@section('title', __('Edit event'))

@section('content')
    <x-classic.parts id="formCommunityEvent" name="form" :title="__('Edit event')">
        <form method="POST" action="{{ route('group.events.update', $event) }}" enctype="multipart/form-data">
            @csrf
            <x-classic.required-notice />
            <table>
                @include('group-event._fields', ['event' => $event])
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
                @include('group-event._image_fields')
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                    <li><a href="{{ route('group.events.show', $event) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>

    {{-- OpenPNE 3 editSuccess.php's buttonBox kind, body-less: the kind puts its form inside the
         operation li. --}}
    <x-classic.parts id="toDelete" name="buttonBox" :title="__('Delete the event and comments')">
        <div class="operation">
            <ul class="moreInfo button">
                <li>
                    <form method="GET" action="{{ route('group.events.delete.show', $event) }}">
                        <input type="submit" class="input_submit" value="{{ __('Delete') }}">
                    </form>
                </li>
            </ul>
        </div>
    </x-classic.parts>
@endsection
