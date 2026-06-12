{{-- OpenPNE 3 localNav (admin Navigation data, secure pages only): the `default` set on the
     viewer's own pages, the `friend` set (the subject's id-scoped links) when viewing another
     member. The subject is recorded by Controller::markLocalNavSubject (OpenPNE 3 sf_nav_id); the
     renderer threads its id into a `:id` slot or as `?id=`. The community context is deferred. --}}
@auth
    @php
        $navService = app(\App\Services\NavigationService::class);
        $navSubject = request()->attributes->get('localNavSubject');
        $navType = $navSubject !== null ? 'friend' : 'default';
        $navItems = $navService->visibleEntries($navType, app()->getLocale(), $navSubject?->getKey());
    @endphp
    @if (! empty($navItems))
        <ul class="{{ $navType }}">
            @foreach ($navItems as $item)
                @include('layouts.partials.nav-item', ['item' => $item])
            @endforeach
        </ul>
    @endif
@endauth
