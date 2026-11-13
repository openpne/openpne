<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
// Honor LARAVEL_STORAGE_PATH (see bootstrap/app.php) so this pre-boot check finds
// the maintenance file under a relocated storage directory.
$storagePath = getenv('LARAVEL_STORAGE_PATH') ?: __DIR__.'/../storage';
if (file_exists($maintenance = $storagePath.'/framework/maintenance.php')) {
    require $maintenance;
}

// A signed variant URL is answered from the image cache without booting the framework
// (docs/internals/images.md, "The fast path").
require __DIR__.'/../bootstrap/image-fast-path.php';
if (openpne_image_fast_path_serve($_SERVER, $_GET, $_COOKIE, $storagePath)) {
    exit;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
