@php($siteIcon = app(\App\Files\SiteIcon::class))
@if ($siteIcon->state() === \App\Files\SiteIconState::Uploaded)
    <link rel="icon" type="image/png" href="{{ brand_favicon_url() }}">
@elseif ($siteIcon->state() === \App\Files\SiteIconState::Generated)
    <link rel="icon" href="{{ route('favicon.ico', ['v' => $siteIcon->version()]) }}" sizes="32x32">
    <link rel="icon" href="{{ route('favicon.svg', ['v' => $siteIcon->version()]) }}" type="image/svg+xml">
@endif
    <link rel="apple-touch-icon" href="{{ $siteIcon->appIconUrl(180) }}">
    <link rel="manifest" href="{{ route('webmanifest') }}">
