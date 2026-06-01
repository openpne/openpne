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

];
