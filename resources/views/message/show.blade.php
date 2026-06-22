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

            @php($id = $view->message->getKey())
            <div class="operation">
                <ul class="moreInfo button">
                    @if ($view->box === \App\Features\Message\MessageBox::Receive)
                        {{-- OpenPNE 3 shows Reply on a received message whose sender still exists. --}}
                        @if ($view->message->sender !== null)
                            <li><a href="{{ route('message.reply', ['message' => $id]) }}" class="input_submit">{{ __('Reply') }}</a></li>
                        @endif
                        <li>
                            <form method="POST" action="{{ route('message.receive.trash', ['message' => $id]) }}">
                                @csrf
                                <button type="submit" class="input_submit">{{ __('Delete') }}</button>
                            </form>
                        </li>
                    @elseif ($view->box === \App\Features\Message\MessageBox::Sent)
                        <li>
                            <form method="POST" action="{{ route('message.send.trash', ['message' => $id]) }}">
                                @csrf
                                <button type="submit" class="input_submit">{{ __('Delete') }}</button>
                            </form>
                        </li>
                    @elseif ($view->box === \App\Features\Message\MessageBox::Trash)
                        <li>
                            <form method="POST" action="{{ route('message.trash.restore', ['message' => $id]) }}">
                                @csrf
                                <button type="submit" class="input_submit">{{ __('Restore') }}</button>
                            </form>
                        </li>
                        <li><a href="{{ route('message.trash.purge.confirm', ['message' => $id]) }}" class="input_submit">{{ __('Delete permanently') }}</a></li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
@endsection
