@props(['message'])
@php($entities = $message->mentions->map(fn ($mention) => [
    'offset' => $mention->offset,
    'length' => $mention->length,
    'kind' => 'mention',
    // The mentioned member is linked by id, so a rename since the message never breaks the target.
    'href' => route('member.profile.show', $mention->member_id),
])->sortBy('offset')->values()->all())
{{-- EntityText::render returns already-escaped, safe HTML, as in <x-timeline-body>; talk parses no
     hashtags, so mentions are the only entities. --}}
{!! \App\Support\EntityText::render($message->body, $entities) !!}
