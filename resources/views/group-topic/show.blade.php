@extends('layouts.classic')

@section('title', $topic->name)

@section('content')
    {{-- OpenPNE 3 showSuccess.php hand-writes the article as a topicDetailBox with no id, so the
         kind is restored and OpenPNE 4's id kept. --}}
    <x-classic.parts id="communityTopic_show" name="topicDetailBox" :title="$topic->name">
        <p class="topicMeta">
            @if ($topic->member)
                <a href="{{ route('member.profile.show', $topic->member) }}">{{ $topic->member->name }}</a><x-classic.ai-mark :is-ai="$topic->member->isAiAccount()" />
            @else
                {{ __('Withdrawn member') }}
            @endif
            &mdash; {{ \App\Support\LocalizedDate::dateTime($topic->created_at) }}
        </p>
        <div class="topicBody">
            @include('group-topic._images', ['images' => $topic->images])
            <x-user-text :value="$topic->body" :format="$topic->format" />
            <x-link-card :record="$topic" />
        </div>

        @if ($canEdit)
            <p>
                <a href="{{ route('group.topics.edit', $topic) }}">{{ __('Edit') }}</a>
                <a href="{{ route('group.topics.delete.show', $topic) }}">{{ __('Delete') }}</a>
            </p>
        @endif
    </x-classic.parts>

    @if ($thread->total > 0)
        <x-classic.parts id="communityTopic_comment_list" name="commentList" :title="__('Comments')">
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
                                @if (\App\Features\GroupTopic\GroupTopicAccess::canDeleteComment($comment, auth()->user()))
                                    <a href="{{ route('group.topics.comment.delete.show', $comment) }}">{{ __('Delete') }}</a>
                                @endif
                            </p>
                        </div>
                        <div class="body">
                            @include('group-topic._images', ['images' => $comment->images])
                            <p class="text"><x-user-text :value="$comment->body" /></p>
                            <x-link-card :record="$comment" />
                        </div>
                    </dd>
                </dl>
            @endforeach
        </x-classic.parts>
    @endif

    @if ($canComment)
        <x-classic.parts id="formCommunityTopicComment" name="form" :title="__('Post a comment')">
            <form method="POST" action="{{ route('group.topics.comment.store', $topic) }}" enctype="multipart/form-data">
                @csrf
                <x-classic.required-notice />
                <table>
                    <tr>
                        <th><label for="comment_body">{{ __('Comment') }} <x-classic.required-mark /></label></th>
                        <td>
                            <textarea id="comment_body" name="body" rows="8" required>{{ old('body') }}</textarea>
                            @error('body')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    @include('group-topic._image_fields')
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                    </ul>
                </div>
            </form>
        </x-classic.parts>
    @endif

    {{-- OpenPNE 3 closes the page with this line box, linking to the community top page. --}}
    <x-classic.parts id="linkLine" name="line">
        <a href="{{ route('group.topics.index', $topic->group) }}">{{ $topic->group->name }}</a>
    </x-classic.parts>
@endsection
