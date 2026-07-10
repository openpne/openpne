{{-- The localNav bar (admin Navigation data, secure pages only): the `community` set on a
     community page, the `friend` set (the subject's id-scoped links) when viewing another member,
     the `default` set on the viewer's own pages. The community / subject is recorded by
     Controller::markLocalNavCommunity / markLocalNavSubject; the renderer threads its id into a
     `:id` slot or as `?id=`. Community wins over the subject. --}}
@auth
    @php
        $navService = app(\App\Services\NavigationService::class);
        $navCommunity = request()->attributes->get('localNavCommunity');
        $navSubject = request()->attributes->get('localNavSubject');
        [$navType, $navContextId] = match (true) {
            $navCommunity !== null => ['community', $navCommunity->getKey()],
            $navSubject !== null => ['friend', $navSubject->getKey()],
            default => ['default', null],
        };
        $navItems = $navService->visibleEntries($navType, app()->getLocale(), $navContextId);
    @endphp
    @if (! empty($navItems))
        <ul class="{{ $navType }}">
            @foreach ($navItems as $item)
                @include('layouts.partials.nav-item', ['item' => $item])
            @endforeach
        </ul>
    @endif
@endauth
