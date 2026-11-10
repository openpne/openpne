<div class="pagerRelative">
    @if ($thread->hasOlder())
        <p class="prev"><a href="{{ $thread->link($thread->olderPage(), $thread->ascending) }}">{{ __('Older') }}</a></p>
    @endif
    <p class="number">{{ __('No. :first - :last', ['first' => $thread->firstNumber(), 'last' => $thread->lastNumber()]) }}</p>
    @if ($thread->hasNewer())
        <p class="next"><a href="{{ $thread->link($thread->newerPage(), $thread->ascending) }}">{{ __('Newer') }}</a></p>
    @endif
</div>
