{{-- Maps a context's gadget zones onto the Classic shell sections. An empty side zone is left
     undefined so its structural div is omitted. Two optional page-owned slots for boxes that are
     not gadgets: $topPrepend renders at the head of #Top (above the columns — the profile page's
     own-page / add-%friend% box, as OpenPNE 3 placed it), $contentTop ahead of the contents zone
     (the home cautions, which OpenPNE 3 folded into the information box area). --}}
@if (! empty($topPrepend) || ! empty($zones['top'] ?? []))
    @section('top')@includeWhen(! empty($topPrepend), $topPrepend ?? '')<x-gadget-zone :items="$zones['top'] ?? []" />@endsection
@endif
@if (! empty($zones['sideMenu'] ?? []))
    @section('sidemenu')<x-gadget-zone :items="$zones['sideMenu']" />@endsection
@endif
@section('content')@includeWhen(! empty($contentTop), $contentTop ?? '')<x-gadget-zone :items="$zones['contents'] ?? []" />@endsection
@if (! empty($zones['bottom'] ?? []))
    @section('bottom')<x-gadget-zone :items="$zones['bottom']" />@endsection
@endif
