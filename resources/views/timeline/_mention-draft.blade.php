{{-- A failed validation flashes the picker's payload with the body; re-rendering it keeps the
     draft's mentions across the redirect, with or without JS — old('body') already keeps the text,
     and losing only the invisible rows would silently unlink the mention. The script re-anchors
     these (fromPayload) and the server re-validates whatever is resubmitted. --}}
@foreach (array_values(array_filter((array) old('mentions', []), 'is_array')) as $i => $row)
    <input type="hidden" data-mention name="mentions[{{ $i }}][member_id]" value="{{ is_scalar($row['member_id'] ?? null) ? $row['member_id'] : '' }}">
    <input type="hidden" data-mention name="mentions[{{ $i }}][offset]" value="{{ is_scalar($row['offset'] ?? null) ? $row['offset'] : '' }}">
    <input type="hidden" data-mention name="mentions[{{ $i }}][length]" value="{{ is_scalar($row['length'] ?? null) ? $row['length'] : '' }}">
@endforeach
