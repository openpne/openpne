<?php

return [

    // Any https origin may register (docs/internals/mcp.md, "Signing in from a client"); the https-or-loopback
    // rule itself is App\Mcp\OAuth\RegisterController's.
    'redirect_domains' => ['*'],

    'custom_schemes' => [],

];
