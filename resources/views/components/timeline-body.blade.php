@props(['post'])
@php($entities = $post->mentions->map(fn ($mention) => [
    'offset' => $mention->offset,
    'length' => $mention->length,
    'kind' => 'mention',
    // The mentioned member is linked by id, so a rename since the post never breaks the target.
    'href' => route('member.profile.show', $mention->member_id),
])->all())
{{-- EntityText::render returns already-escaped, safe HTML (links, line breaks, one anchor per
     mention); raw output is intentional, as in <x-user-text>. --}}
{!! \App\Support\EntityText::render($post->body, $entities) !!}
