{{-- Push ownership reconcile on every authenticated Classic page: rebind this browser's push
     subscription to the member signed in now, failing closed when the server cannot confirm the
     rebind (see public/js/push-reconcile.js). The Classic half of the Modern shell's UnreadSync
     reconcile, so a shared-browser account switch cannot keep delivering the prior member's pushes
     on either surface. Gated to a signed-in member on a push-configured site: a guest has no
     subscription of its own to rebind, and an unconfigured site has no push at all. --}}
@if (request()->user('member') instanceof \App\Models\Member && \App\Notifications\Push\WebPushConfig::configured())
    @once
        <script src="{{ asset('js/push-reconcile.js') }}" defer></script>
    @endonce
@endif
