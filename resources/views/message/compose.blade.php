@extends('layouts.classic')

@section('title', __('Compose Message'))

@section('content')
    <div class="dparts form" id="message_compose">
        <div class="partsHeading"><h3>{{ __('Compose Message') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('message.compose.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="to" value="{{ $recipient->getKey() }}">
                @isset($parentId)<input type="hidden" name="parent_id" value="{{ $parentId }}">@endisset
                @isset($threadId)<input type="hidden" name="thread_id" value="{{ $threadId }}">@endisset
                <table>
                    <tr>
                        <th>{{ __('Recipient') }}</th>
                        <td><a href="{{ route('member.profile.show', $recipient) }}">{{ $recipient->name }}</a></td>
                    </tr>
                    @include('message._fields', ['subject' => $subject ?? '', 'body' => $body ?? ''])
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
