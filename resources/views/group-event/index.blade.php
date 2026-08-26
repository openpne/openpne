@extends('layouts.classic')

@section('title', $group->name)

@section('content')
    {{-- OpenPNE 3 listCommunitySuccess.php: a buttonBox (id communityEventList) with the Create
         button above a recentList box (no id; the id is OpenPNE 4's) headed "List of events", one dl
         per event, the pager above and below. --}}
    @if ($canPost)
        <x-classic.parts id="communityEventList" name="buttonBox" :title="__('Create a new event')">
            <div class="operation">
                <ul class="moreInfo button">
                    <li>
                        <form action="{{ route('group.events.new', $group) }}" method="get">
                            <input type="submit" class="input_submit" value="{{ __('Create') }}">
                        </form>
                    </li>
                </ul>
            </div>
        </x-classic.parts>
    @endif

    <x-classic.parts id="communityEvent_board" name="recentList" :title="__('List of events')">
        @if ($events->isEmpty())
            <p>{{ __('No events to show.') }}</p>
        @else
            {{-- One dl per event: last-activity datetime in the dt, and the link labelled
                 "name(count)" — no space, untruncated. --}}
            <x-classic.pager :paginator="$events->withQueryString()" />
            @foreach ($events as $event)
                <dl>
                    <dt>{{ \App\Support\LocalizedDate::dateTime($event->updated_at) }}</dt>
                    <dd><a href="{{ route('group.events.show', $event) }}">{{ $event->name }}({{ $event->comments_count }})</a></dd>
                </dl>
            @endforeach
            <x-classic.pager :paginator="$events->withQueryString()" />
        @endif
    </x-classic.parts>

    <x-classic.parts id="linkLine" name="line">
        <a href="{{ route('group.show', $group) }}">[{{ $group->name }}] {{ __('%Community% Top Page') }}</a>
    </x-classic.parts>
@endsection
