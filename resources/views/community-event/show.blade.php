@extends('layouts.classic')

@section('title', $event->name)

@section('content')
    {{-- OpenPNE 3 showSuccess.php renders the event as a listBox whose id is the bare
         "communityEvent"; skins target it, so it is restored verbatim. --}}
    <x-classic.parts id="communityEvent" name="listBox" :title="$event->name">
        <table class="eventDetail">
            <tr>
                <th>{{ __('Writer') }}</th>
                <td>
                    @if ($event->member)
                        <a href="{{ route('member.profile.show', $event->member) }}">{{ $event->member->name }}</a>
                    @else
                        {{ __('Withdrawn member') }}
                    @endif
                </td>
            </tr>
            <tr>
                <th>{{ __('Open date') }}</th>
                <td>
                    {{ \App\Support\LocalizedDate::date($event->open_date) }}
                    @if ($event->open_date_comment !== '')
                        {{ $event->open_date_comment }}
                    @endif
                </td>
            </tr>
            <tr>
                <th>{{ __('Area') }}</th>
                {{-- Rendered as user text so a bare venue/online-meeting URL autolinks. --}}
                <td><x-user-text :value="$event->area" /></td>
            </tr>
            <tr>
                <th>{{ __('Application deadline') }}</th>
                <td>{{ $event->application_deadline ? \App\Support\LocalizedDate::date($event->application_deadline) : '—' }}</td>
            </tr>
            <tr>
                <th>{{ __('Capacity') }}</th>
                <td>{{ $event->capacity ?? '—' }}</td>
            </tr>
            <tr>
                <th>{{ __('Count of Member') }}</th>
                <td>
                    {{ $event->participantCount() }}
                    @if ($event->participantCount() > 0)
                        (<a href="{{ route('communityEvent.member_list', $event) }}">{{ __('See Member List') }}</a>)
                    @endif
                </td>
            </tr>
        </table>

        <div class="eventBody">
            @include('community-event._images', ['images' => $event->images])
            <x-user-text :value="$event->body" :format="$event->format" />
        </div>

        @if ($canEdit)
            <p>
                <a href="{{ route('communityEvent.edit', $event) }}">{{ __('Edit') }}</a>
                <a href="{{ route('communityEvent.delete.show', $event) }}">{{ __('Delete') }}</a>
            </p>
        @endif
    </x-classic.parts>

    @if ($thread->total > 0)
        <x-classic.parts id="communityEvent_comment_list" name="commentList" :title="__('Comments')">
            {{-- Reversible pager (fixed size 20), order toggle. --}}
            @if ($thread->hasPages())
                <div class="pagerRelative">
                    @if ($thread->ascending)
                        <a href="{{ $thread->link(1, false) }}">{{ __('View Latest') }}</a>
                    @else
                        <a href="{{ $thread->link(1, true) }}">{{ __('View Oldest First') }}</a>
                    @endif
                </div>
                <div class="pagerRelative">
                    @if ($thread->hasOlder())
                        <p class="prev"><a href="{{ $thread->link($thread->olderPage(), $thread->ascending) }}">{{ __('Older') }}</a></p>
                    @endif
                    <p class="number">{{ __('No. :first - :last', ['first' => $thread->firstNumber(), 'last' => $thread->lastNumber()]) }}</p>
                    @if ($thread->hasNewer())
                        <p class="next"><a href="{{ $thread->link($thread->newerPage(), $thread->ascending) }}">{{ __('Newer') }}</a></p>
                    @endif
                </div>
            @endif

            @foreach ($thread->comments as $comment)
                <dl>
                    <dt>{{ \App\Support\LocalizedDate::dateTime($comment->created_at) }}</dt>
                    <dd>
                        <div class="title">
                            <p class="heading">
                                <strong>{{ $comment->number }}</strong>:
                                @if ($comment->member)
                                    <a href="{{ route('member.profile.show', $comment->member) }}">{{ $comment->member->name }}</a>
                                @else
                                    {{ __('Withdrawn member') }}
                                @endif
                                @if (\App\Features\CommunityEvent\CommunityEventAccess::canDeleteComment($comment, auth()->user()))
                                    <a href="{{ route('communityEvent.comment.delete.show', $comment) }}">{{ __('Delete') }}</a>
                                @endif
                            </p>
                        </div>
                        <div class="body">
                            @include('community-event._images', ['images' => $comment->images])
                            <p class="text"><x-user-text :value="$comment->body" /></p>
                        </div>
                    </dd>
                </dl>
            @endforeach
        </x-classic.parts>
    @endif

    @if ($canComment)
        {{-- OpenPNE 3 hand-writes this one instead of calling its form helper: the <form> wraps a
             lone .parts.form, not the other way round, and the box carries no id. --}}
        <form method="POST" action="{{ route('communityEvent.comment.store', $event) }}" enctype="multipart/form-data">
            @csrf
            <x-classic.parts id="communityEvent_comment_form" name="form" :single="true" :title="__('Post a new event comment')">
                <table>
                    <tr>
                        <th><label for="comment_body">{{ __('Comment') }}</label></th>
                        <td>
                            <textarea id="comment_body" name="body" rows="8" required>{{ old('body') }}</textarea>
                            @error('body')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    @include('community-event._image_fields')
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        {{-- The participate/cancel button only shows while the roster is open:
                             hidden once closed/expired, and "Participate" is hidden when the
                             viewer is already in or the event is full. --}}
                        @if (! $isClosed && ! $isExpired)
                            @if ($isParticipant)
                                <li><input type="submit" name="cancel" class="input_submit" value="{{ __('Cancel to join') }}"></li>
                            @elseif (! $isFull)
                                <li><input type="submit" name="participate" class="input_submit" value="{{ __('Participate in this event') }}"></li>
                            @endif
                        @endif
                        <li><input type="submit" name="comment" class="input_submit" value="{{ __('Add a comment only') }}"></li>
                    </ul>
                </div>
            </x-classic.parts>
        </form>
    @endif

    {{-- OpenPNE 3 closes the page with this line box, linking to the community top page. --}}
    <x-classic.parts id="linkLine" name="line">
        <a href="{{ route('communityEvent.index', $event->community) }}">{{ $event->community->name }}</a>
    </x-classic.parts>
@endsection
