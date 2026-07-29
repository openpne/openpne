{{-- OpenPNE 3's timeline partials use_stylesheet these themselves (component-driven, not the
     module's view.yml), so every container that renders timeline rows includes this — once per
     page, whatever mix of screens and gadgets drew them. Bootstrap before timeline.css, the
     OpenPNE 3 load order. --}}
@once
    @push('pluginCss')
        <link rel="stylesheet" href="{{ asset('opTimelinePlugin/css/bootstrap.css') }}">
        <link rel="stylesheet" href="{{ asset('opTimelinePlugin/css/timeline.css') }}">
    @endpush
@endonce
