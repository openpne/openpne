{{-- Overrides framework mail::header: drops the slot==='Laravel' branch that injects laravel.com's logo image, so the header is always the (sns_name) text. --}}
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
</a>
</td>
</tr>
