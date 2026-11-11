@extends('layouts.classic')

@section('title', $topic->name)

@section('content')
    {{-- OpenPNE 3 showSuccess.php hand-writes the article as a topicDetailBox with no id (the id is
         OpenPNE 4's): one dl whose dt is the stacked datetime and whose dd holds the title, name
         and body blocks, then the Edit button; deletion is reached from the edit screen. --}}
    <x-classic.parts id="communityTopic_show" name="topicDetailBox" :hand-written="true" :title="'['.$topic->group->name.'] '.__('%Topic%')">
        <dl>
            <dt>@foreach (\App\Support\LocalizedDate::dateTimeLines($topic->created_at) as $line){{ $line }}@if (! $loop->last)<br />@endif@endforeach</dt>
            <dd>
                <div class="title">
                    <p>{{ $topic->name }}</p>
                </div>
                <div class="name">
                    <p>
                        @if ($topic->member)
                            <a href="{{ route('member.profile.show', $topic->member) }}">{{ $topic->member->name }}</a><x-classic.ai-mark :is-ai="$topic->member->isAiAccount()" />
                        @else
                            {{ __('%Withdrawn_member%') }}
                        @endif
                    </p>
                </div>
                <div class="body">
                    @include('group-topic._images', ['images' => $topic->images])
                    @include('group-topic._files', ['images' => $topic->images])
                    @if ($topic->format === \App\Support\BodyFormat::Markdown)
                        <x-user-text :value="$topic->body" :format="$topic->format" />
                    @else
                        <p class="text"><x-user-text :value="$topic->body" :format="$topic->format" /></p>
                    @endif
                    <x-link-card :record="$topic" />
                </div>
            </dd>
        </dl>
        @if ($canEdit)
            <div class="operation">
                <form action="{{ route('group.topics.edit', $topic) }}" method="get">
                    <ul class="moreInfo button">
                        <li><input class="input_submit" type="submit" value="{{ __('Edit') }}"></li>
                    </ul>
                </form>
            </div>
        @endif
    </x-classic.parts>

    @if ($thread->total > 0)
        <x-classic.parts id="communityTopic_comment_list" name="commentList" :hand-written="true" :title="__('Comments')">
            {{-- Reversible pager (fixed size 20), order toggle. --}}
            @if ($thread->hasPages())
                <div class="pagerRelative">
                    @if ($thread->ascending)
                        <a href="{{ $thread->link(1, false) }}">{{ __('View Latest') }}</a>
                    @else
                        <a href="{{ $thread->link(1, true) }}">{{ __('View Oldest First') }}</a>
                    @endif
                </div>
                <x-classic.comment-pager :thread="$thread" />
            @endif

            @foreach ($thread->comments as $comment)
                <dl>
                    <dt>@foreach (\App\Support\LocalizedDate::dateTimeLines($comment->created_at) as $line){{ $line }}@if (! $loop->last)<br />@endif@endforeach</dt>
                    <dd>
                        <div class="title">
                            <p class="heading">
                                <strong>{{ $comment->number }}</strong>:
                                @if ($comment->member)
                                    <a href="{{ route('member.profile.show', $comment->member) }}">{{ $comment->member->name }}</a>
                                @else
                                    {{ __('%Withdrawn_member%') }}
                                @endif
                                @if (\App\Features\GroupTopic\GroupTopicAccess::canDeleteComment($comment, auth()->user()))
                                    <a href="{{ route('group.topics.comment.delete.show', $comment) }}">{{ __('Delete') }}</a>
                                @endif
                                @if ($commentReplyLink && $canComment)
                                    <a class="reply" href="#formCommunityTopicComment" data-comment-reply="#community_topic_comment_body" data-number="{{ $comment->number }}" data-name="{{ $comment->member?->name ?? __('%Withdrawn_member%') }}">{{ __('Reply') }}</a>
                                @endif
                            </p>
                        </div>
                        <div class="body">
                            @include('group-topic._images', ['images' => $comment->images])
                            @include('group-topic._files', ['images' => $comment->images])
                            <p class="text"><x-user-text :value="$comment->body" /></p>
                            <x-link-card :record="$comment" />
                        </div>
                    </dd>
                </dl>
            @endforeach
            @if ($thread->hasPages())
                <x-classic.comment-pager :thread="$thread" />
            @endif
        </x-classic.parts>
    @endif
    @if ($commentReplyLink && $canComment)
        @once
            <script src="{{ asset('js/classic-comment-reply.js') }}" defer @scriptNonce></script>
        @endonce
    @endif

    @if ($canComment)
        <x-classic.parts id="formCommunityTopicComment" name="form" :title="__('Post a comment')">
            <form method="POST" action="{{ route('group.topics.comment.store', $topic) }}" enctype="multipart/form-data">
                @csrf
                <x-classic.required-notice />
                <table>
                    <tr>
                        <th><label for="community_topic_comment_body">{{ __('Comment') }} <x-classic.required-mark /></label></th>
                        <td>
                            <textarea id="community_topic_comment_body" name="body" rows="8" required>{{ old('body') }}</textarea>
                            @error('body')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    <x-classic.photo-rows name="community_topic_comment" />
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Post') }}"></li>
                    </ul>
                </div>
            </form>
        </x-classic.parts>
    @endif

    {{-- OpenPNE 3 closes the page with this line box, linking to the community top page. --}}
    <x-classic.parts id="linkLine" name="line">
        <a href="{{ route('group.show', $topic->group) }}">[{{ $topic->group->name }}] {{ __('%Community% Top Page') }}</a>
    </x-classic.parts>
@endsection
