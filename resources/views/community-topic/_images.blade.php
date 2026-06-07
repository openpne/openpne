{{-- A post's attached images as square thumbnails linking to the full bytes, matching OpenPNE 3's
     <ul class="photo"> markup (the .commentList dd ul.photo theme style and customer CSS target it).
     Each fetch is gated by FilePolicy, so a members-only board's images stay private. $images = the
     post's images (CommunityTopicImage / CommunityTopicCommentImage), number-ordered. --}}
@if ($images->isNotEmpty())
    <ul class="photo">
        @foreach ($images as $image)
            @continue($image->file === null)
            <li><a href="{{ $image->file->url() }}" target="_blank" rel="noopener"><img src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt=""></a></li>
        @endforeach
    </ul>
@endif
