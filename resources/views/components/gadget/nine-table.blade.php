{{-- OpenPNE 3 nineTable: a row × col thumbnail grid (friend/community list). `items` are
     ['url','imageUrl','name']; `type` (full|only_image|only_name) selects the photo/text rows. --}}
@props(['items' => [], 'rows' => 3, 'cols' => 3, 'type' => 'full'])
@php($items = collect($items)->values())
@php($cols = max(1, (int) $cols))
@php($rowCount = (int) min((int) $rows, (int) ceil($items->count() / $cols)))
@if ($items->isNotEmpty())
    <table>
        @for ($i = 1; $i <= $rowCount; $i++)
            @if ($type === 'full' || $type === 'only_image')
                <tr class="photo">
                    @for ($j = ($i * $cols) - $cols; $j < $i * $cols; $j++)
                        @php($item = $items[$j] ?? null)
                        <td>
                            @if ($item)
                                <a href="{{ $item['url'] }}">
                                    @if ($item['imageUrl'])
                                        <img src="{{ $item['imageUrl'] }}" alt="{{ $item['name'] }}">
                                    @endif
                                </a>
                            @endif
                        </td>
                    @endfor
                </tr>
            @endif
            @if ($type === 'full' || $type === 'only_name')
                <tr class="text">
                    @for ($j = ($i * $cols) - $cols; $j < $i * $cols; $j++)
                        @php($item = $items[$j] ?? null)
                        <td>
                            @if ($item)
                                <a href="{{ $item['url'] }}">{{ $item['name'] }}</a>
                            @endif
                        </td>
                    @endfor
                </tr>
            @endif
        @endfor
    </table>
@endif
