@php($e = $event ?? null)
<tr>
    <th><label for="event_name">{{ __('Title') }}</label></th>
    <td>
        <input type="text" class="input_text" id="event_name" name="name" value="{{ old('name', $e?->name) }}" required>
        @error('name')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
<tr>
    <th><label for="event_open_date">{{ __('Open date') }}</label></th>
    <td>
        <input type="date" class="input_text" id="event_open_date" name="open_date" value="{{ old('open_date', $e?->open_date?->format('Y-m-d')) }}" required>
        <input type="text" class="input_text" id="event_open_date_comment" name="open_date_comment" value="{{ old('open_date_comment', $e?->open_date_comment) }}" placeholder="{{ __('e.g. 19:00 start') }}">
        @error('open_date')<p class="error">{{ $message }}</p>@enderror
        @error('open_date_comment')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
<tr>
    <th><label for="event_area">{{ __('Area') }}</label></th>
    <td>
        <input type="text" class="input_text" id="event_area" name="area" value="{{ old('area', $e?->area) }}" required>
        @error('area')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
<tr>
    <th><label for="event_body">{{ __('Body') }}</label></th>
    <td>
        <textarea id="event_body" name="body" required>{{ old('body', $e?->body) }}</textarea>
        @error('body')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
<tr>
    <th><label for="event_application_deadline">{{ __('Application deadline') }}</label></th>
    <td>
        <input type="date" class="input_text" id="event_application_deadline" name="application_deadline" value="{{ old('application_deadline', $e?->application_deadline?->format('Y-m-d')) }}">
        @error('application_deadline')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
<tr>
    <th><label for="event_capacity">{{ __('Capacity') }}</label></th>
    <td>
        <input type="number" class="input_text" id="event_capacity" name="capacity" value="{{ old('capacity', $e?->capacity) }}" min="0">
        @error('capacity')<p class="error">{{ $message }}</p>@enderror
    </td>
</tr>
