{{-- Up to MAX_IMAGES file inputs for attaching pictures or files to a message. The enclosing <form>
     must carry enctype="multipart/form-data". --}}
<tr>
    <th>{{ __('Attachments') }}</th>
    <td>
        @for ($i = 0; $i < \App\Files\PostImages::MAX_IMAGES; $i++)
            <p><input type="file" name="images[]"></p>
        @endfor
        @php($imageError = collect($errors->messages())->keys()->first(fn ($key) => str_starts_with($key, 'images')))
        @if ($imageError)<p class="error">{{ $errors->first($imageError) }}</p>@endif
    </td>
</tr>
