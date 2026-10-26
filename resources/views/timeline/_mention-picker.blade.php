{{-- The compose forms' @mention picker (public/js/classic-timeline-mention.js). It enhances a plain
     textarea, so it loads with the form that has one rather than from the layout — and once per
     page, whatever mix of screens and gadgets rendered them. --}}
@once
    @push('pluginCss')
        <link rel="stylesheet" href="{{ asset('css/classic-timeline-mention.css') }}">
    @endpush
    <template id="classic-mention-avatar"><img class="classicMentionAvatar" src="{{ asset('images/no_image.gif') }}" width="24" height="24" alt=""></template>
    <script src="{{ asset('js/classic-timeline-mention.js') }}" defer></script>
@endonce
