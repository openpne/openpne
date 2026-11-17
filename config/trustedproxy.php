<?php

// The framework's TrustProxies reads this key on each request when bootstrap/app.php named no
// proxies, and an empty list, unlike null, trusts none whatever the host is
// (docs/internals/runtime.md, "Reverse proxy & HTTPS").
$proxies = trim((string) env('TRUSTED_PROXIES'));

return [
    'proxies' => $proxies === '' ? [] : $proxies,
];
