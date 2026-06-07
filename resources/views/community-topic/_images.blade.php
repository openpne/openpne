{{-- Renders a post's attached images as square thumbnails linking to the full bytes. Each fetch is
     gated by FilePolicy, so a members-only board's images stay private. $images = the post's images
     (CommunityTopicImage / CommunityTopicCommentImage), number-ordered. --}}
@if ($images->isNotEmpty())
    <ul class="topicImages">
        @foreach ($images as $image)
            @continue($image->file === null)
            <li><a href="{{ $image->file->url() }}"><img src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt=""></a></li>
        @endforeach
    </ul>
@endif
