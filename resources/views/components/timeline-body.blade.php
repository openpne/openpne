@props(['post'])
@php($entities = $post->mentions->map(fn ($mention) => [
    'offset' => $mention->offset,
    'length' => $mention->length,
    'kind' => 'mention',
    // The mentioned member is linked by id, so a rename since the post never breaks the target.
    'href' => route('member.profile.show', $mention->member_id),
])->concat($post->tags->map(fn ($tag) => [
    'offset' => $tag->offset,
    'length' => $tag->length,
    'kind' => 'hashtag',
    // The normalized tag addresses the page; the range still shows the text that was typed.
    'href' => route('timeline.tag', $tag->tag),
]))
    // EntityText walks one ascending list. The two sets never intersect (the parser drops a
    // candidate overlapping a mention), so ordering by offset is all the merge needs.
    ->sortBy('offset')->values()->all())
{{-- EntityText::render returns already-escaped, safe HTML (links, line breaks, one anchor per
     entity); raw output is intentional, as in <x-user-text>. --}}
{!! \App\Support\EntityText::render($post->body, $entities) !!}
