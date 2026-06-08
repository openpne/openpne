<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File storage backend
    |--------------------------------------------------------------------------
    |
    | Where uploaded file bytes are stored. 'blob' (the default) keeps them in
    | the database `file_bin` table via DbBlobFileStorage, so a whole site is a
    | single DB dump — the OpenPNE 3 heritage layout. Any other value names a
    | disk declared in config/filesystems.php (e.g. 'local', 's3'), served by
    | DiskFileStorage. See App\Providers\FilesServiceProvider.
    |
    */

    'files' => [
        'disk' => env('OPENPNE_FILES_DISK', 'blob'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image thumbnails
    |--------------------------------------------------------------------------
    |
    | Thumbnails are generated on demand (intervention/image) and cached on the
    | 'cache_disk' filesystem disk. 'allowed_sizes' is a whitelist of WxH targets:
    | an unlisted size is rejected (404), so a request cannot drive unbounded
    | thumbnail generation / cache growth. Matches OpenPNE 3's default set.
    |
    */

    'images' => [
        'driver' => env('OPENPNE_IMAGE_DRIVER', 'gd'), // gd (default) | imagick | vips
        'cache_disk' => env('OPENPNE_IMAGE_CACHE_DISK', 'image_cache'),
        'quality' => (int) env('OPENPNE_IMAGE_QUALITY', 85),
        'allowed_sizes' => ['48x48', '76x76', '120x120', '180x180', '240x320', '320x320', '600x600'],
        // Reject uploads larger than this on a side. The decoder allocates
        // width*height*4 bytes, so an unbounded dimension is a decompression-bomb
        // (memory exhaustion) vector even within the file-size limit.
        'max_upload_dimension' => (int) env('OPENPNE_IMAGE_MAX_DIMENSION', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Surface (Classic / Modern) selection
    |--------------------------------------------------------------------------
    |
    | Read by App\Support\SurfaceResolver. 'tenant_mode' = 'modern_only' forces
    | every canonical route to Modern; 'mixed' (the default) serves Classic on a
    | canonical route and Modern under /m/*. 'tenant_default_surface' picks which
    | a canonical route renders in 'mixed' mode, and which surface the root (/)
    | landing uses. See docs/internals/classic-compatibility.md.
    |
    */

    'tenant_mode' => env('OPENPNE_TENANT_MODE', 'mixed'), // mixed | modern_only
    'tenant_default_surface' => env('OPENPNE_TENANT_DEFAULT_SURFACE', 'classic'), // classic | modern

    /*
    |--------------------------------------------------------------------------
    | Classic shell
    |--------------------------------------------------------------------------
    |
    | 'footer_html' is the Classic footer bar content. OpenPNE 3 made this
    | admin-configurable (SnsConfig footer_before/after); this env seam stands in
    | until that admin surface lands. It is trusted operator HTML, rendered raw
    | in the footer, so an operator can rebrand without touching the shell.
    |
    */

    'classic' => [
        'footer_html' => env('OPENPNE_CLASSIC_FOOTER_HTML', 'Powered by <a href="https://www.openpne.jp/" target="_blank" rel="noopener">OpenPNE</a>'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Diary
    |--------------------------------------------------------------------------
    |
    | 'allow_web_public' gates the "Public to Web" diary audience, matching
    | OpenPNE 3's op_diary_plugin_use_open_diary (admin-configurable, default on).
    | When false the option is removed from the diary form and rejected on submit,
    | so a site that disabled anonymous-visible diaries in OpenPNE 3 keeps that
    | ability. This env seam stands in until that admin surface lands.
    |
    */

    'diary' => [
        'allow_web_public' => (bool) env('OPENPNE_DIARY_ALLOW_WEB_PUBLIC', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | 'token_ttl_minutes' is how long an emailed registration link stays valid
    | (OpenPNE 3's default registration-URL lifetime was 24h). Expiry is derived
    | from registration_tokens.created_at against this value.
    |
    */

    'registration' => [
        'token_ttl_minutes' => (int) env('OPENPNE_REGISTRATION_TOKEN_TTL_MINUTES', 1440),
    ],

];
