{{-- Maps a context's gadget zones onto the Classic shell sections. Empty side zones are left
     undefined so the shell's layout-letter derivation (top→A, sideMenu→B) stays accurate. --}}
@if (! empty($zones['top'] ?? []))
    @section('top')<x-gadget-zone :items="$zones['top']" />@endsection
@endif
@if (! empty($zones['sideMenu'] ?? []))
    @section('sidemenu')<x-gadget-zone :items="$zones['sideMenu']" />@endsection
@endif
@section('content')<x-gadget-zone :items="$zones['contents'] ?? []" />@endsection
@if (! empty($zones['bottom'] ?? []))
    @section('bottom')<x-gadget-zone :items="$zones['bottom']" />@endsection
@endif
