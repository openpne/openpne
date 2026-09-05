{{-- A page of rows on their own, for classic-timeline-more.js to append: no shell, no script. --}}
@php($canPost = \App\Features\Timeline\TimelinePosting::enabled())
@foreach ($posts as $post)
    @include('timeline._post', ['post' => $post, 'canPost' => $canPost])
@endforeach
