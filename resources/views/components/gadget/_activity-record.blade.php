{{-- OpenPNE 3 default/_activityRecord: one server-rendered activity row. The nesting and class names
     (box_memberImage / box_body / span.content / strong.name / span.bodyText / span.info / span.time /
     span.public_flag / ul.operation) are the custom-CSS seam, so they mirror OpenPNE 3 exactly. The
     delete control shows only on the viewer's own posts. --}}
@php($author = $post->member)
@php($avatar = $author->avatar?->file)
@php($images = $post->images->filter(fn ($image) => $image->file !== null)->take(3))
<li class="activity">
    <div class="box_memberImage">
        <p><a href="{{ route('member.profile.show', $author) }}"><x-classic.image :file="$avatar" :size="48" :alt="$author->name" /></a></p>
    </div>
    <div class="box_body">
        <p>
            <span class="content">
                @if ($images->isNotEmpty())
                    @foreach ($images as $image)
                        <img src="{{ $image->file->thumbnailUrl(48, 48, square: true) }}" alt="">
                    @endforeach
                    <br>
                @endif
                <strong class="name"><a href="{{ route('member.profile.show', $author) }}">{{ $author->name }}</a></strong>
                <span class="bodyText"><x-timeline-body :post="$post" /></span>
            </span>
            <span class="info">
                {{-- OpenPNE 3 showed a relative time; the Classic port links the absolute timestamp used across the timeline to the post permalink. --}}
                <span class="time"><a href="{{ route('timeline.show', $post) }}">{{ \App\Support\LocalizedDate::dateTime($post->created_at) }}</a></span>
                @if ($post->visibility !== \App\Support\Visibility::Members)
                    <span class="public_flag">{{ __('Public flag') }} : {{ __($post->visibility->label()) }}</span>
                @endif
            </span>
        </p>
        @if ($author->is(auth()->user()))
            <ul class="operation">
                <li class="delete"><a href="{{ route('timeline.delete.show', $post) }}">{{ __('Delete') }}</a></li>
            </ul>
        @endif
    </div>
</li>
