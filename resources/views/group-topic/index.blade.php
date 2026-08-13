@extends('layouts.classic')

@section('title', $group->name)

@section('content')
    {{-- OpenPNE 3 listCommunitySuccess.php lists the board in a recentList box (no id) and puts the
         create entry point in a buttonBox above it; OpenPNE 4 folds that button in here. --}}
    <x-classic.parts id="communityTopic_board" name="recentList" :title="$group->name">
        @if ($canPost)
            <div class="operation">
                <ul class="moreInfo button">
                    <li><a href="{{ route('group.topics.new', $group) }}">{{ __('Post a new %topic%') }}</a></li>
                </ul>
            </div>
        @endif

        @if ($topics->isEmpty())
            <p>{{ __('No %topics% to show.') }}</p>
        @else
            {{-- One dl per topic: last-activity datetime in the dt, and the link labelled
                 "name(count)" — no space, untruncated, unlike the diary feed's label. The author is
                 OpenPNE 4's own addition, trailing the link the way _topicCommentSnsListBox.php
                 trails its group name. --}}
            <x-classic.pager :paginator="$topics->withQueryString()" />
            @foreach ($topics as $topic)
                <dl>
                    <dt>{{ \App\Support\LocalizedDate::dateTime($topic->updated_at) }}</dt>
                    <dd><a href="{{ route('group.topics.show', $topic) }}">{{ $topic->name }}({{ $topic->comments_count }})</a>@if ($topic->member) ({{ $topic->member->name }})@endif</dd>
                </dl>
            @endforeach
            <x-classic.pager :paginator="$topics->withQueryString()" />
        @endif
    </x-classic.parts>

    <x-classic.parts name="line">
        <a href="{{ route('group.show', $group) }}">{{ $group->name }}</a>
    </x-classic.parts>
@endsection
