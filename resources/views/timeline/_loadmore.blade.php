{{-- OpenPNE 3's #timeline-loadmore もっと読む. Hidden until classic-timeline-more.js takes over: without
     the script the pager beside the list is the way on. The box carries `hidden`, not the button —
     the vendored bootstrap .btn{display:inline-block} would beat the attribute on the button. --}}
<div data-timeline-loadmore-box hidden>
    <button type="button" class="btn btn-small" id="timeline-loadmore" data-timeline-loadmore data-next-url="{{ $nextUrl }}">{{ __('Load more') }}</button>
    <div id="timeline-loadmore-loading" role="status"><img src="{{ asset('images/ajax-loader.gif') }}" alt="{{ __('Loading…') }}"></div>
</div>
