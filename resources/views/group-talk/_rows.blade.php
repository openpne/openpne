{{-- A page of rows on their own, for classic-timeline-more.js to append: no shell, no script. --}}
@foreach ($messages as $message)
    @include('group-talk._message', ['message' => $message])
@endforeach
