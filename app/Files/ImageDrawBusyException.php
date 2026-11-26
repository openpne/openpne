<?php

namespace App\Files;

/** Another worker's draw of the same cache key outlasted this request's wait; answered as an outage, so retryable. */
class ImageDrawBusyException extends ImageProcessorUnavailableException {}
