{{-- OpenPNE 3 `_partsSearchResultList.php`: the paged member / community result list. One result is
     a table of caption/value rows with a 76×76 photo cell spanning them all — thumbnail link, then a
     "Details" link to the same page. rowspan follows the row count, which varies per result (a member
     with no visible self-introduction contributes one row fewer). The first value prints whole; every
     later one is cut to three cell rows (BodyText::truncateToRows), so one long value cannot stretch
     the band. A pager brackets the list above and below.

     `items` entries: url, name (thumbnail alt), file (a File for <x-classic.image>, null falls back
     to no_image), rows ([['caption' => …, 'value' => …], …], at least one; values are plain strings),
     and optionally isAi, which marks the first row's value as an AI account's name.

     The diary feed hand-writes its own table instead of calling this: OpenPNE 3 hand-writes
     listSuccess.php too, and its band diverges (no Details link, a trailing tr.operation row). --}}
@props(['items' => [], 'paginator' => null])
@if (count($items))
    @if ($paginator)
        <x-classic.pager :paginator="$paginator" />
    @endif
    <div class="block">
        @foreach ($items as $item)
            @php($rows = array_values($item['rows']))
            <div class="ditem"><div class="item"><table><tbody>
                <tr>
                    <td rowspan="{{ count($rows) }}" class="photo">
                        <a href="{{ $item['url'] }}"><x-classic.image :file="$item['file'] ?? null" :size="76" :alt="$item['name']" /></a><br />
                        <a href="{{ $item['url'] }}">{{ __('Details') }}</a>
                    </td>
                    <th>{{ $rows[0]['caption'] }}</th><td>{{ $rows[0]['value'] }}<x-classic.ai-mark :is-ai="$item['isAi'] ?? false" /></td>
                </tr>
                @foreach (array_slice($rows, 1) as $row)
                    <tr>
                        <th>{{ $row['caption'] }}</th><td>{{ \App\Support\BodyText::truncateToRows($row['value']) }}</td>
                    </tr>
                @endforeach
            </tbody></table></div></div>
        @endforeach
    </div>
    @if ($paginator)
        <x-classic.pager :paginator="$paginator" />
    @endif
@endif
