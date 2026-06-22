@extends('layouts.classic')

@section('title', __('Edit draft'))

@section('content')
    <div class="dparts form" id="message_edit">
        <div class="partsHeading"><h3>{{ __('Edit draft') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('message.draft.update', ['message' => $draft->getKey()]) }}" enctype="multipart/form-data">
                @csrf
                <table>
                    @if ($recipient)
                        <tr>
                            <th>{{ __('To') }}</th>
                            <td><a href="{{ route('member.profile.show', $recipient) }}">{{ $recipient->name }}</a></td>
                        </tr>
                    @endif
                    @include('message._fields', ['subject' => $draft->subject, 'body' => $draft->body])
                    @if ($draft->files->isNotEmpty())
                        <tr>
                            <th>{{ __('Current images') }}</th>
                            <td>
                                <ul class="photo">
                                    @foreach ($draft->files as $image)
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
                    @include('message._image_fields')
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><button type="submit" name="action" value="send" class="input_submit">{{ __('Send') }}</button></li>
                        <li><button type="submit" name="action" value="draft" class="input_submit">{{ __('Save as draft') }}</button></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
