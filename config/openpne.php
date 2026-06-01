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
        'driver' => env('OPENPNE_IMAGE_DRIVER', 'gd'), // gd | imagick
        'cache_disk' => env('OPENPNE_IMAGE_CACHE_DISK', 'image_cache'),
        'quality' => (int) env('OPENPNE_IMAGE_QUALITY', 85),
        'allowed_sizes' => ['48x48', '76x76', '120x120', '180x180', '240x320', '320x320', '600x600'],
        // Reject uploads larger than this on a side. The decoder allocates
        // width*height*4 bytes, so an unbounded dimension is a decompression-bomb
        // (memory exhaustion) vector even within the file-size limit.
        'max_upload_dimension' => (int) env('OPENPNE_IMAGE_MAX_DIMENSION', 5000),
    ],

];
