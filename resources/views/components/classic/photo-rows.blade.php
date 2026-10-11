{{-- OpenPNE 3's three embedded ImageForms as one labelled row per slot under the form's name. Uploads
     post as images[] and land in the lowest free number, so a free slot's input and the slots agree;
     an image whose number is outside the slots, or shares one, keeps a remove-only row below them.
     The enclosing <form> must carry enctype="multipart/form-data". --}}
@props(['name', 'images' => null])
@php($bySlot = ($images ?? collect())->keyBy('number'))
@php($drawn = [])
@for ($n = 1; $n <= \App\Files\PostImages::MAX_IMAGES; $n++)
    @php($image = $bySlot->get($n))
    @php($drawn[] = $image?->id)
    <tr>
        <th><label for="{{ $name }}_photo_{{ $n }}_photo">{{ __('Photo :n', ['n' => $n]) }}</label></th>
        <td>
            <ul id="{{ $name }}_photo_{{ $n }}">
                <li>
                    @if ($image)
                        @if ($image->file)
                            <a href="{{ $image->file->url() }}" target="_blank" rel="noopener"><img src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt=""></a><br>
                        @endif
                        <label><input type="checkbox" name="remove_images[]" value="{{ $image->id }}" id="{{ $name }}_photo_{{ $n }}_photo"> {{ __('remove the current photo') }}</label>
                    @else
                        <input type="file" name="images[]" id="{{ $name }}_photo_{{ $n }}_photo" accept="{{ image_upload_accept() }}">
                    @endif
                </li>
            </ul>
        </td>
    </tr>
@endfor
@foreach (($images ?? collect())->reject(fn ($image) => in_array($image->id, $drawn, true)) as $image)
    <tr>
        <th><label>{{ __('Photo :n', ['n' => $image->number]) }}</label></th>
        <td>
            <ul>
                <li>
                    @if ($image->file)
                        <a href="{{ $image->file->url() }}" target="_blank" rel="noopener"><img src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt=""></a><br>
                    @endif
                    <label><input type="checkbox" name="remove_images[]" value="{{ $image->id }}"> {{ __('remove the current photo') }}</label>
                </li>
            </ul>
        </td>
    </tr>
@endforeach
@php($imageError = collect($errors->messages())->keys()->first(fn ($key) => str_starts_with($key, 'images')))
@if ($imageError)
    <tr>
        <th></th>
        <td><p class="error">{{ $errors->first($imageError) }}</p></td>
    </tr>
@endif
