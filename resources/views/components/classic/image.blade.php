{{-- Member/community thumbnail; falls back to the OpenPNE 3 no_image.gif when unset. `size` must be an
     allowed thumbnail size; `display` draws it in a smaller box (OpenPNE 3 drew a 48px avatar at 36). --}}
@props(['file', 'size', 'alt', 'display' => null])
@if ($file)
    <img src="{{ $file->thumbnailUrl($size, $size, square: true) }}"@if ($display !== null) width="{{ $display }}" height="{{ $display }}"@endif alt="{{ $alt }}">
@else
    <img src="{{ asset('images/no_image.gif') }}" width="{{ $display ?? $size }}" height="{{ $display ?? $size }}" alt="{{ $alt }}">
@endif
