<tr>
    <th><label for="topic_name">{{ __('Title') }}</label></th>
    <td>
        <input type="text" class="input_text" id="topic_name" name="name" value="{{ old('name', $name ?? '') }}" required>
        @error('name')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
<tr>
    <th><label for="topic_body">{{ __('Body') }}</label></th>
    <td>
        <textarea id="topic_body" name="body" required>{{ old('body', $body ?? '') }}</textarea>
        @error('body')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
