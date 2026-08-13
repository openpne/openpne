{{-- The localNav bar (admin Navigation data, secure pages only): the `group` set on a group
     page, the `friend` set (the subject's id-scoped links) when viewing another member, the
     `default` set on the viewer's own pages. The group / subject is recorded by
     Controller::markLocalNavGroup / markLocalNavSubject; the renderer threads its id into a
     `:id` slot or as `?id=`. The group wins over the subject. The `class` is the presentation
     token, so the stored `group` type still renders OpenPNE 3's `community` for custom CSS. --}}
@auth
    @php
        $navService = app(\App\Services\NavigationService::class);
        $navGroup = request()->attributes->get('localNavGroup');
        $navSubject = request()->attributes->get('localNavSubject');
        [$navType, $navContextId] = match (true) {
            $navGroup !== null => ['group', $navGroup->getKey()],
            $navSubject !== null => ['friend', $navSubject->getKey()],
            default => ['default', null],
        };
        $navItems = $navService->visibleEntries($navType, app()->getLocale(), $navContextId);
    @endphp
    @if (! empty($navItems))
        <ul class="{{ \App\Models\Navigation::presentationToken($navType) }}">
            @foreach ($navItems as $item)
                @include('layouts.partials.nav-item', ['item' => $item])
            @endforeach
        </ul>
    @endif
@endauth
