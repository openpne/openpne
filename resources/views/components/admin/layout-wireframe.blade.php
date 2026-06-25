@props(['layout' => 'layoutA'])

@php
    // viewBox 240×200; panes mirror the OpenPNE 3 gadget zones (top / side = left column / contents /
    // bottom). Geometry is carried from the prototype admin wireframes and recolored to currentColor so
    // it tracks the surrounding text color (selected = primary, idle = gray) and works in light/dark.
    $panes = match ($layout) {
        'layoutA' => [[14, 14, 212, 34, 'Top'], [14, 58, 64, 92, 'Side'], [86, 58, 140, 92, 'Contents'], [14, 160, 212, 28, 'Bottom']],
        'layoutB' => [[14, 14, 64, 136, 'Side'], [86, 14, 140, 136, 'Contents'], [14, 160, 212, 28, 'Bottom']],
        'layoutC' => [[14, 14, 212, 136, 'Contents'], [14, 160, 212, 28, 'Bottom']],
        'layoutD' => [[14, 14, 212, 174, 'Contents']],
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
    @foreach ($panes as [$x, $y, $w, $h, $label])
        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" rx="2"
              fill="currentColor" fill-opacity="0.13" stroke="currentColor" stroke-opacity="0.55" stroke-width="1.2" />
        <text x="{{ $x + $w / 2 }}" y="{{ $y + $h / 2 }}"
              text-anchor="middle" dominant-baseline="middle"
              fill="currentColor" fill-opacity="0.8" style="font:600 11px sans-serif;">{{ $label }}</text>
    @endforeach
</svg>
