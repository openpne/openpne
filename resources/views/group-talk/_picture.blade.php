{{-- The first picture, so a message without words is still recognizable where it is confirmed. --}}
@php($picture = \App\Files\PostAttachments::partition($message->images)['images']->first())
@if ($picture?->file)
    <div><img class="timeline-post-image" src="{{ $picture->file->thumbnailUrl(120, 120, square: true) }}" alt=""></div>
@endif
