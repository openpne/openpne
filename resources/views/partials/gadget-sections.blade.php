{{-- Maps a context's gadget zones onto the Classic shell sections. An empty side zone is left
     undefined so its structural div is omitted. $contentTop (optional view name) renders ahead
     of the contents zone, for page-owned boxes that are not gadgets. --}}
@if (! empty($zones['top'] ?? []))
    @section('top')<x-gadget-zone :items="$zones['top']" />@endsection
@endif
@if (! empty($zones['sideMenu'] ?? []))
    @section('sidemenu')<x-gadget-zone :items="$zones['sideMenu']" />@endsection
@endif
@section('content')@includeWhen(! empty($contentTop), $contentTop ?? '')<x-gadget-zone :items="$zones['contents'] ?? []" />@endsection
@if (! empty($zones['bottom'] ?? []))
    @section('bottom')<x-gadget-zone :items="$zones['bottom']" />@endsection
@endif
