@extends('layouts.classic')

@section('title', $topic->name)

@section('content')
    <div class="dparts" id="communityTopic_show">
        <div class="partsHeading"><h3>{{ $topic->name }}</h3></div>
        <div class="parts">
            <p class="topicMeta">
                @if ($topic->member)
                    <a href="{{ route('member.profile.show', $topic->member) }}">{{ $topic->member->name }}</a>
                @else
                    {{ __('Withdrawn member') }}
                @endif
                &mdash; {{ \App\Support\LocalizedDate::dateTime($topic->created_at) }}
            </p>
            <div class="topicBody"><x-user-text :value="$topic->body" /></div>
            @include('community-topic._images', ['images' => $topic->images])

            @if ($canEdit)
                <p>
                    <a href="{{ route('communityTopic.edit', $topic) }}">{{ __('Edit') }}</a>
                    <a href="{{ route('communityTopic.delete.show', $topic) }}">{{ __('Delete') }}</a>
                </p>
            @endif
        </div>
    </div>

    @if ($thread->total > 0)
        <div class="dparts commentList" id="communityTopic_comment_list">
            <div class="partsHeading"><h3>{{ __('Comments') }}</h3></div>
            <div class="parts">
                {{-- OpenPNE 3 communityTopicComment list: reversible pager (fixed size 20), order toggle. --}}
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
                                    @if (\App\Features\CommunityTopic\CommunityTopicAccess::canDeleteComment($comment, auth()->user()))
                                        <a href="{{ route('communityTopic.comment.delete.show', $comment) }}">{{ __('Delete') }}</a>
                                    @endif
                                </p>
                            </div>
                            <div class="body"><p class="text"><x-user-text :value="$comment->body" /></p></div>
                            @include('community-topic._images', ['images' => $comment->images])
                        </dd>
                    </dl>
                @endforeach
            </div>
        </div>
    @endif

    @if ($canComment)
        <div class="dparts form" id="communityTopic_comment_form">
            <div class="partsHeading"><h3>{{ __('Post a comment') }}</h3></div>
            <div class="parts">
                <form method="POST" action="{{ route('communityTopic.comment.store', $topic) }}" enctype="multipart/form-data">
                    @csrf
                    <table>
                        <tr>
                            <th><label for="comment_body">{{ __('Comment') }}</label></th>
                            <td>
                                <textarea id="comment_body" name="body" rows="8" required>{{ old('body') }}</textarea>
                                @error('body')<p class="error">{{ $message }}</p>@enderror
                            </td>
                        </tr>
                        @include('community-topic._image_fields')
                    </table>
                    <div class="operation">
                        <ul class="moreInfo button">
                            <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                        </ul>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <div class="line">
        <a href="{{ route('communityTopic.index', $topic->community) }}">{{ $topic->community->name }}</a>
    </div>
@endsection
