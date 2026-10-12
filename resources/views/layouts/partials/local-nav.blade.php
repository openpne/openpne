{{-- The localNav bar (admin Navigation data, secure pages only): the `group` set on a group
     page, the `friend` set (the subject's id-scoped links) when viewing another member, the
     `default` set on the viewer's own pages. The group / subject is recorded by
     Controller::markLocalNavGroup / markLocalNavSubject; the renderer threads its id into a
     `:id` slot or as `?id=`. The group wins over the subject. The `class` is the presentation
     token, so the stored `group` type still renders OpenPNE 3's `community` for custom CSS. --}}
@auth
    @php
        $navService = app(\App\Services\NavigationService::class);
        $navType = classic_local_nav_type();
        $navContextId = match ($navType) {
            'group' => request()->attributes->get('localNavGroup')->getKey(),
            'friend' => request()->attributes->get('localNavSubject')->getKey(),
            default => null,
        };
        $navItems = $navService->visibleEntries($navType, app()->getLocale(), $navContextId);
    @endphp
    @if (! empty($navItems))
        {{-- The theme plugin's _localNav.php writes the items straight into the header's list, with no
             ul.<type> of their own ($bare). --}}
        @if ($bare ?? false)
            @foreach ($navItems as $item)
                @include('layouts.partials.nav-item', ['item' => $item])
            @endforeach
        @else
            <ul class="{{ \App\Models\Navigation::presentationToken($navType) }}">
                @foreach ($navItems as $item)
                    @include('layouts.partials.nav-item', ['item' => $item])
                @endforeach
            </ul>
        @endif
    @endif
@endauth
