@extends('layouts.classic')

@section('title', __('Notifications'))

@section('content')
    {{-- No OpenPNE 3 screen to port: the feed borrows the recentList skin the diary and board
         lists use (one dl per row, datetime in the dt), with the row's sentence as the submit that
         opens it. Where a row leads is decided by that POST, so listing costs no per-row lookup. --}}
    {{-- Opening a row marks it read, so it submits rather than links. The chrome comes off so a
         row still reads as a row: `input_submit` is OpenPNE 3's styling for a form's confirm
         button, and colour is inherited so a theme's own palette carries. --}}
    @once
        {{-- Class selectors, not ids: this block sits after the site's own CSS, so a site beats it
             with an ordinary more-specific selector. Ids here would leave nothing to outrank. --}}
        <style>
            .notificationFeedRow { display: inline; }
            .notificationFeedLink { padding: 0; border: 0; background: none; font: inherit; color: inherit; text-align: left; text-decoration: underline; cursor: pointer; }
        </style>
    @endonce
    <x-classic.parts id="notification_feed" name="recentList" :title="__('Notifications')">
        @if ($feed->isEmpty())
            <div class="body">{{ __('No notifications yet.') }}</div>
        @else
            <x-classic.pager :paginator="$feed" />
            @foreach ($feed as $item)
                <dl>
                    <dt>@if ($item->createdAt){{ \App\Support\LocalizedDate::dateTime($item->createdAt) }}@endif</dt>
                    <dd>
                        <form method="POST" action="{{ route('notifications.open', $item->id) }}" class="notificationFeedRow">
                            @csrf
                            {{-- Unread is the row's emphasis, not a colour: strong carries it to a
                                 screen reader too, and the skins already style it. --}}
                            <button type="submit" class="notificationFeedLink">@if ($item->read){{ $item->label }}@else<strong>{{ $item->label }}</strong>@endif</button>
                        </form>
                    </dd>
                </dl>
            @endforeach
            <x-classic.pager :paginator="$feed" />
        @endif
        @if ($unreadCount > 0)
            <div class="operation">
                <ul class="moreInfo button">
                    <li>
                        <form method="POST" action="{{ route('notifications.readAll') }}">
                            @csrf
                            <button type="submit" class="input_submit">{{ __('Mark all as read') }}</button>
                        </form>
                    </li>
                </ul>
            </div>
        @endif
    </x-classic.parts>
@endsection
