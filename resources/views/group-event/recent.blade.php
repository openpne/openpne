@extends('layouts.classic')

@section('title', __('Recently Posted %Community% Events'))

@section('content')
    {{-- OpenPNE 3 recentlyEventListSuccess.php: one dl per event, the untruncated name(count) link
         then (community name), the pager above and below. --}}
    <x-classic.parts id="communityEvent_recent" name="recentList" :hand-written="true" :title="__('Recently Posted %Community% Events')">
        @if ($events->isEmpty())
            <p>{{ __('No events to show.') }}</p>
        @else
            <x-classic.pager :paginator="$events->withQueryString()" />
            @foreach ($events as $event)
                <dl>
                    <dt>{{ \App\Support\LocalizedDate::dateTime($event->bumped_at) }}</dt>
                    <dd><a href="{{ route('group.events.show', $event) }}">{{ $event->name }}({{ $event->comments_count }})</a> ({{ $event->group->name }})</dd>
                </dl>
            @endforeach
            <x-classic.pager :paginator="$events->withQueryString()" />
        @endif
    </x-classic.parts>
@endsection
