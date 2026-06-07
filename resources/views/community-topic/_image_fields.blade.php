{{-- Up to MAX_IMAGES file inputs for attaching images to a topic or comment. The enclosing
     <form> must carry enctype="multipart/form-data". --}}
<tr>
    <th>{{ __('Images') }}</th>
    <td>
        @for ($i = 0; $i < \App\Http\Requests\CommunityTopic\TopicImageRules::MAX_IMAGES; $i++)
            <p><input type="file" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp"></p>
        @endfor
        @php($imageError = collect($errors->messages())->keys()->first(fn ($key) => str_starts_with($key, 'images')))
        @if ($imageError)<p class="error">{{ $errors->first($imageError) }}</p>@endif
    </td>
</tr>
