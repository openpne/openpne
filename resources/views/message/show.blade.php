@extends('layouts.classic')

@section('title', $view->message->subject)

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

            <div class="block">
                <p class="text"><x-user-text :value="$view->message->body" /></p>
            </div>
        </div>
    </div>
@endsection
