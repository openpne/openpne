{{-- Body-format control shared by the compose forms (diary / topic / event new + edit). $format is
     the record's current BodyFormat, or null on create.

     An op3 body has no author-facing editor (it exists only on OpenPNE 3 migrations), so it shows a
     note and emits no field — an absent `format` preserves op3 server-side. Otherwise the checkbox
     posts format=markdown when checked; the hidden field before it posts plain when it is not, so
     unchecking a Markdown record really switches it back to plain (an absent field would preserve
     the current format on update). --}}
@php($format = $format ?? null)
<tr>
    <th></th>
    <td>
        @if ($format === \App\Support\BodyFormat::Op3)
            <p class="note">{{ __('This entry keeps its OpenPNE 3 formatting.') }}</p>
        @else
            <label class="markdownToggle">
                <input type="hidden" name="format" value="plain">
                <input type="checkbox" name="format" value="markdown" @checked(old('format', $format?->value) === \App\Support\BodyFormat::Markdown->value)>
                {{ __('Write in Markdown') }}
            </label>
        @endif
    </td>
</tr>
