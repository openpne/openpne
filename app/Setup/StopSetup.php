<?php

declare(strict_types=1);

namespace App\Setup;

use RuntimeException;

/** Ends openpne:setup with exit code 1, once it has said why. */
final class StopSetup extends RuntimeException {}
