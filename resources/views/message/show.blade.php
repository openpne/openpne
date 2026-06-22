@extends('layouts.classic')

{{-- A null subject (legacy data) must stay the inline form: @section('title', null) would open a
     block buffer with no @endsection. --}}
@section('title', $view->message->subject ?? '')

@section('sidemenu')
    <x-message.sidemenu :current="$view->box" :linkCurrent="true" />
@endsection

@section('content')
    @php($showRoute = $view->box->showRoute())
    <div class="dparts messageDetailBox" id="message_show">
        <div class="parts">
            <div class="partsHeading"><h3>{{ __('Message') }}</h3></div>

            @if ($view->previousId || $view->nextId)
                <div class="block prevNextLinkLine">
                    @if ($view->previousId)
                        <p class="prev"><a href="{{ route($showRoute, ['message' => $view->previousId]) }}">{{ __('Previous') }}</a></p>
                    @endif
                    @if ($view->nextId)
                        <p class="next"><a href="{{ route($showRoute, ['message' => $view->nextId]) }}">{{ __('Next') }}</a></p>
                    @endif
                </div>
            @endif

            <table>
                <tr>
                    <th>{{ $view->viewerIsSender ? __('To') : __('From') }}</th>
                    <td>
                        <ul>
                            @forelse ($view->counterparties as $member)
                                <li><a href="{{ route('member.profile.show', $member) }}">{{ $member->name }}</a></li>
                            @empty
                                <li>{{ __('Withdrawn member') }}</li>
                            @endforelse
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Created At') }}</th>
                    <td>{{ \App\Support\LocalizedDate::dateTime($view->message->created_at) }}</td>
                </tr>
                <tr>
                    <th>{{ __('Subject') }}</th>
                    <td>{{ $view->message->subject }}</td>
                </tr>
            </table>

            @if ($view->message->files->isNotEmpty())
                <div class="block">
                    <ul class="photo">
                        @foreach ($view->message->files as $image)
                            @continue($image->file === null)
                            <li>
                                <a href="{{ $image->file->url() }}" target="_blank" rel="noopener">
                                    <img src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt="">
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="block">
                <p class="text"><x-user-text :value="$view->message->body ?? ''" /></p>
            </div>

            {{-- OpenPNE 3 shows Reply on a received (non-trash) message whose sender still exists. --}}
            @if ($view->box === \App\Features\Message\MessageBox::Receive && $view->message->sender !== null)
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><a href="{{ route('message.reply', ['message' => $view->message->getKey()]) }}" class="input_submit">{{ __('Reply') }}</a></li>
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endsection
