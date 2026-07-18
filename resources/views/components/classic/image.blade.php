{{-- Member/community thumbnail; falls back to the OpenPNE 3 no_image.gif when unset. --}}
@props(['file', 'size', 'alt'])
@if ($file)
    <img src="{{ $file->thumbnailUrl($size, $size, square: true) }}" alt="{{ $alt }}">
@else
    <img src="{{ asset('images/no_image.gif') }}" width="{{ $size }}" height="{{ $size }}" alt="{{ $alt }}">
@endif
