<?php

// The framework's TrustProxies reads this key on each request when bootstrap/app.php names no
// proxies; null trusts none (docs/internals/runtime.md, "Reverse proxy & HTTPS").
$proxies = trim((string) env('TRUSTED_PROXIES'));

return [
    'proxies' => $proxies === '' ? null : $proxies,
];
