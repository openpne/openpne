@props(['layout' => 'layoutA'])

@php
    // viewBox 240×200; panes mirror the gadget zones (top / sideMenu = left column / contents /
    // bottom), worded as the zone picker words them. Recolored to currentColor so it tracks the
    // surrounding text color (selected = primary, idle = gray) and works in light/dark.
    $panes = match ($layout) {
        'layoutA' => [[14, 14, 212, 34, 'top'], [14, 58, 64, 92, 'sideMenu'], [86, 58, 140, 92, 'contents'], [14, 160, 212, 28, 'bottom']],
        'layoutB' => [[14, 14, 64, 136, 'sideMenu'], [86, 14, 140, 136, 'contents'], [14, 160, 212, 28, 'bottom']],
        'layoutC' => [[14, 14, 212, 136, 'contents'], [14, 160, 212, 28, 'bottom']],
        'layoutD' => [[14, 14, 212, 174, 'contents']],
        default => [],
    };
@endphp

<svg
    viewBox="0 0 240 200"
    role="img"
    aria-label="{{ $layout }} wireframe"
    {{ $attributes->merge(['style' => 'display:block;width:100%;height:auto;color:inherit;']) }}
>
    <rect x="2" y="2" width="236" height="196" rx="3"
          fill="currentColor" fill-opacity="0.04" stroke="currentColor" stroke-opacity="0.35" stroke-width="1.5" />
    @foreach ($panes as [$x, $y, $w, $h, $zone])
        @php
            $label = \App\Gadgets\GadgetLayout::zoneLabel($zone);
            // A wide label (the side column's, in Japanese) is squeezed into its pane rather than
            // spilling over the neighbour; ~5.5 units per half-width column at this font size.
            $squeeze = mb_strwidth($label) * 5.5 > $w - 6 ? ' textLength="'.($w - 6).'" lengthAdjust="spacingAndGlyphs"' : '';
        @endphp
        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" rx="2"
              fill="currentColor" fill-opacity="0.13" stroke="currentColor" stroke-opacity="0.55" stroke-width="1.2" />
        <text x="{{ $x + $w / 2 }}" y="{{ $y + $h / 2 }}"
              text-anchor="middle" dominant-baseline="middle"
              fill="currentColor" fill-opacity="0.8" style="font:600 11px sans-serif;"{!! $squeeze !!}>{{ $label }}</text>
    @endforeach
</svg>
