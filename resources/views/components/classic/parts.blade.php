{{-- The body structure (.body, .block, a bare table) stays with the caller, as it did with OpenPNE 3's
     per-kind body partial. `hand-written` marks a frame OpenPNE 3 wrote as literal markup, which keeps
     .parts under a theme where this frame turns into .well (docs/internals/classic-compatibility.md, "Themes"). --}}
@props(['id' => null, 'name' => null, 'single' => null, 'title' => null, 'handWritten' => false])
@php($single ??= in_array($name, ['informationBox', 'line', 'memberImageBox', 'searchFormLine'], true))
@php($frame = $handWritten ? 'parts' : classic_frame_inner_class())
@php($outerClass = ($single ? $frame : 'dparts').($name ? ' '.$name : ''))
@if ($single)
    <div class="{{ $outerClass }}"@if ($id) id="{{ $id }}"@endif>
        @if (isset($heading))
            <div class="partsHeading">{{ $heading }}</div>
        @elseif ($title !== null && $title !== '')
            <div class="partsHeading"><h3>{{ $title }}</h3></div>
        @endif
        @if ($frame === 'well' && $name === 'informationBox')
            <div>Information</div>
        @endif
        {{ $slot }}
    </div>
@else
    <div class="{{ $outerClass }}"@if ($id) id="{{ $id }}"@endif>
        <div class="{{ $frame }}">
            @if (isset($heading))
                <div class="partsHeading">{{ $heading }}</div>
            @elseif ($title !== null && $title !== '')
                <div class="partsHeading"><h3>{{ $title }}</h3></div>
            @endif
            {{ $slot }}
        </div>
    </div>
@endif
