@extends('layouts.classic')

@section('title', $group->name)

@section('content')
    {{-- OpenPNE 3 listCommunitySuccess.php: a buttonBox (id communityTopicList) with the Create
         button above a recentList box (no id; the id is OpenPNE 4's) headed "List of topics", one dl
         per topic, the pager above and below. --}}
    @if ($canPost)
        <x-classic.parts id="communityTopicList" name="buttonBox" :title="__('Create a new %topic%')">
            <div class="operation">
                <ul class="moreInfo button">
                    <li>
                        <form action="{{ route('group.topics.new', $group) }}" method="get">
                            <input type="submit" class="input_submit" value="{{ __('Create') }}">
                        </form>
                    </li>
                </ul>
            </div>
        </x-classic.parts>
    @endif

    <x-classic.parts id="communityTopic_board" name="recentList" :title="__('List of %topics%')">
        @if ($topics->isEmpty())
            <p>{{ __('No %topics% to show.') }}</p>
        @else
            {{-- One dl per topic: last-activity datetime in the dt, and the link labelled
                 "name(count)" — no space, untruncated, unlike the diary feed's label. --}}
            <x-classic.pager :paginator="$topics->withQueryString()" />
            @foreach ($topics as $topic)
                <dl>
                    <dt>{{ \App\Support\LocalizedDate::dateTime($topic->updated_at) }}</dt>
                    <dd><a href="{{ route('group.topics.show', $topic) }}">{{ $topic->name }}({{ $topic->comments_count }})</a></dd>
                </dl>
            @endforeach
            <x-classic.pager :paginator="$topics->withQueryString()" />
        @endif
    </x-classic.parts>

    <x-classic.parts id="linkLine" name="line">
        <a href="{{ route('group.show', $group) }}">[{{ $group->name }}] {{ __('%Community% Top Page') }}</a>
    </x-classic.parts>
@endsection
