{{-- OpenPNE 3's embedded photo forms (opCommunityTopicPluginImageForm ×3): one labelled row per
     slot, the file input inside ul#community_{kind}_photo_N. An occupied slot shows the current
     photo over a replacement input and a remove checkbox (_formEditImage.php); the inputs post as
     images[] / remove_images[], so replacing is a remove plus an upload. The enclosing <form> must
     carry enctype="multipart/form-data". --}}
@props(['kind', 'images' => null])
@php($existing = $images?->values() ?? collect())
@for ($i = 0; $i < \App\Files\PostImages::MAX_IMAGES; $i++)
    @php($n = $i + 1)
    @php($image = $existing->get($i))
    <tr>
        <th><label for="community_{{ $kind }}_photo_{{ $n }}_photo">{{ __('Photo :n', ['n' => $n]) }}</label></th>
        <td>
            <ul id="community_{{ $kind }}_photo_{{ $n }}">
                <li>
                    @if ($image?->file)
                        <a href="{{ $image->file->url() }}" target="_blank" rel="noopener"><img src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt=""></a><br>
                    @endif
                    <input type="file" name="images[]" id="community_{{ $kind }}_photo_{{ $n }}_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                    @if ($image)
                        <br><label><input type="checkbox" name="remove_images[]" value="{{ $image->id }}"> {{ __('remove the current photo') }}</label>
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
