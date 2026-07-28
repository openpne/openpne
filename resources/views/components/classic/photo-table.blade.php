{{-- OpenPNE 3 `_partsPhotoTable.php`: the paged member/community grid. Each band is two rows —
     tr.photo thumbnails then tr.text names — padded to `col` with genuinely empty <td> (skins size
     the column; a &nbsp; would print). A pager brackets the table above and below.

     Deliberately a separate implementation from <x-gadget.nine-table>: OpenPNE 3 keeps
     _partsNineTable.php and _partsPhotoTable.php as twin partials, and they diverge (the gadget grid
     caps at `rows` and has no pager, this one paginates), so folding them would couple two shapes
     that OpenPNE 3 lets drift apart.

     `items` entries: url, name (required); file (a File for <x-classic.image>, null falls back to
     no_image), count (rendered as "name (count)", omitted when null), crown (bool), action
     (['url', 'label'] appended bare in the name cell). `paginator` may be null for an unpaged grid. --}}
@props(['items' => [], 'col' => 5, 'paginator' => null])
@php($list = collect($items)->values())
@php($col = max(1, (int) $col))
@if ($list->isNotEmpty())
    @if ($paginator)
        <x-classic.pager :paginator="$paginator" />
    @endif
    <table>
        @for ($i = 1; $i <= (int) ceil($list->count() / $col); $i++)
            <tr class="photo">
                @for ($j = ($i * $col) - $col; $j < $i * $col; $j++)
                    @php($item = $list[$j] ?? null)
                    <td>@if ($item)@if ($item['crown'] ?? false)<p class="crown"><img src="{{ asset('images/icon_crown.gif') }}" alt="admin"></p>@endif<a href="{{ $item['url'] }}"><x-classic.image :file="$item['file'] ?? null" :size="76" :alt="$item['name']" /></a>@endif</td>
                @endfor
            </tr>
            <tr class="text">
                @for ($j = ($i * $col) - $col; $j < $i * $col; $j++)
                    @php($item = $list[$j] ?? null)
                    <td>@if ($item)<a href="{{ $item['url'] }}">{{ $item['name'] }}@isset($item['count']) ({{ $item['count'] }})@endisset</a>@isset($item['action']) <a href="{{ $item['action']['url'] }}">{{ $item['action']['label'] }}</a>@endisset@endif</td>
                @endfor
            </tr>
        @endfor
    </table>
    @if ($paginator)
        <x-classic.pager :paginator="$paginator" />
    @endif
@endif
