@extends('layouts.classic')

@section('title', $group->name)

@section('content')
    {{-- OpenPNE 3 listCommunitySuccess.php lists the board in a recentList box (no id) and puts the
         create entry point in a buttonBox above it; OpenPNE 4 folds that button in here. --}}
    <x-classic.parts id="communityEvent_board" name="recentList" :title="$group->name">
        @if ($canPost)
            <div class="operation">
                <ul class="moreInfo button">
                    <li><a href="{{ route('group.events.new', $group) }}">{{ __('Post a new event') }}</a></li>
                </ul>
            </div>
        @endif

        @if ($events->isEmpty())
            <p>{{ __('No events to show.') }}</p>
        @else
            {{-- One dl per event: last-activity datetime in the dt, and the link labelled
                 "name(count)" — no space, untruncated. The open date is OpenPNE 4's own addition,
                 trailing the link the way _eventCommentSnsListBox.php trails its community name —
                 one parenthetical, as that precedent; the author stays on the show page. --}}
            <x-classic.pager :paginator="$events->withQueryString()" />
            @foreach ($events as $event)
                <dl>
                    <dt>{{ \App\Support\LocalizedDate::dateTime($event->updated_at) }}</dt>
                    <dd><a href="{{ route('group.events.show', $event) }}">{{ $event->name }}({{ $event->comments_count }})</a> ({{ \App\Support\LocalizedDate::date($event->open_date) }})</dd>
                </dl>
            @endforeach
            <x-classic.pager :paginator="$events->withQueryString()" />
        @endif
    </x-classic.parts>

    <x-classic.parts name="line">
        <a href="{{ route('group.show', $group) }}">{{ $group->name }}</a>
    </x-classic.parts>
@endsection
