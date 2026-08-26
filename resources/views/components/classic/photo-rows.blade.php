{{-- OpenPNE 3's embedded photo forms (opCommunityTopicPluginImageForm ×3): one labelled row per
     slot, keyed by the image's persisted number. A free slot holds the file input (uploads post as
     images[] and land in the lowest free number, so the rows and the slots agree); an occupied slot
     shows its photo and the remove checkbox (remove_images[]). OpenPNE 3 also swapped a photo in
     place from the same row; here that is a removal now and an upload into the freed slot. The
     enclosing <form> must carry enctype="multipart/form-data". --}}
@props(['kind', 'images' => null])
@php($bySlot = ($images ?? collect())->keyBy('number'))
@for ($n = 1; $n <= \App\Files\PostImages::MAX_IMAGES; $n++)
    @php($image = $bySlot->get($n))
    <tr>
        <th><label for="community_{{ $kind }}_photo_{{ $n }}_photo">{{ __('Photo :n', ['n' => $n]) }}</label></th>
        <td>
            <ul id="community_{{ $kind }}_photo_{{ $n }}">
                <li>
                    @if ($image)
                        @if ($image->file)
                            <a href="{{ $image->file->url() }}" target="_blank" rel="noopener"><img src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt=""></a><br>
                        @endif
                        <label><input type="checkbox" name="remove_images[]" value="{{ $image->id }}" id="community_{{ $kind }}_photo_{{ $n }}_photo"> {{ __('remove the current photo') }}</label>
                    @else
                        <input type="file" name="images[]" id="community_{{ $kind }}_photo_{{ $n }}_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                    @endif
                </li>
            </ul>
        </td>
    </tr>
@endfor
@php($imageError = collect($errors->messages())->keys()->first(fn ($key) => str_starts_with($key, 'images')))
@if ($imageError)
    <tr>
        <th></th>
        <td><p class="error">{{ $errors->first($imageError) }}</p></td>
    </tr>
@endif
