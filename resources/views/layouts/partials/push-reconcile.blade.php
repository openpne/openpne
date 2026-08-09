{{-- Push ownership reconcile on every authenticated Classic page: rebind this browser's push
     subscription to the member signed in now (only on an ownership transition), failing closed when
     the server rejects the rebind POST (see public/js/push-reconcile.js). The Classic half of the
     Modern shell's UnreadSync reconcile, so a shared-browser account switch cannot keep delivering the
     prior member's pushes on either surface. Gated to a signed-in member on a push-configured site: a
     guest has no subscription of its own to rebind, and an unconfigured site has no push at all. --}}
@if (request()->user('member') instanceof \App\Models\Member && \App\Notifications\Push\WebPushConfig::configured())
    @once
        {{-- data-push-member-id feeds the reconcile its owner: the script rebinds only on an ownership
             transition (a different member/endpoint), so it needs to know who is signed in now. --}}
        <script src="{{ asset('js/push-reconcile.js') }}" data-push-member-id="{{ (int) request()->user('member')->getKey() }}" defer></script>
    @endonce
@endif
