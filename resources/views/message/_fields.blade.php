<tr>
    <th><label for="message_subject">{{ __('Subject') }}</label></th>
    <td>
        <input type="text" class="input_text" id="message_subject" name="subject" value="{{ old('subject', $subject ?? '') }}" required>
        @error('subject')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
<tr>
    <th><label for="message_body">{{ __('Body') }}</label></th>
    <td>
        <textarea id="message_body" name="body" required>{{ old('body', $body ?? '') }}</textarea>
        @error('body')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
