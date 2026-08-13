{{-- A post's attached images as square thumbnails linking to the full bytes. The <ul class="photo">
     markup is a theme/customer CSS seam. Each fetch is gated by FilePolicy, so a members-only
     community's event images stay private. $images = the post's images (GroupEventImage /
     GroupEventCommentImage), number-ordered. --}}
@if ($images->isNotEmpty())
    <ul class="photo">
        @foreach ($images as $image)
            @continue($image->file === null)
            <li><a href="{{ $image->file->url() }}" target="_blank" rel="noopener"><img src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt=""></a></li>
        @endforeach
    </ul>
@endif
