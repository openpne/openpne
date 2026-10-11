<tr>
    <th><label for="community_topic_name">{{ __('Title') }} <x-classic.required-mark /></label></th>
    <td>
        <input type="text" class="input_text" id="community_topic_name" name="name" value="{{ old('name', $name ?? '') }}" required>
        @error('name')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
<tr>
    <th><label for="community_topic_body">{{ __('Body') }} <x-classic.required-mark /></label></th>
    <td>
        <textarea id="community_topic_body" name="body" required>{{ old('body', $body ?? '') }}</textarea>
        @error('body')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
@include('compose._format-toggle', ['format' => $format ?? null])
