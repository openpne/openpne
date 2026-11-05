@extends('layouts.classic')

@section('title', __('Recently Posted %Community% %Topics%'))

@section('content')
    {{-- OpenPNE 3 recentlyTopicListSuccess.php: one dl per topic, the untruncated name(count) link
         then (community name), the pager above and below. --}}
    <x-classic.parts id="communityTopic_recent" name="recentList" :hand-written="true" :title="__('Recently Posted %Community% %Topics%')">
        @if ($topics->isEmpty())
            <p>{{ __('No %topics% to show.') }}</p>
        @else
            <x-classic.pager :paginator="$topics->withQueryString()" />
            @foreach ($topics as $topic)
                <dl>
                    <dt>{{ \App\Support\LocalizedDate::dateTime($topic->bumped_at) }}</dt>
                    <dd><a href="{{ route('group.topics.show', $topic) }}">{{ $topic->name }}({{ $topic->comments_count }})</a> ({{ $topic->group->name }})</dd>
                </dl>
            @endforeach
            <x-classic.pager :paginator="$topics->withQueryString()" />
        @endif
    </x-classic.parts>
@endsection
