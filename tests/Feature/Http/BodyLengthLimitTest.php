<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

class BodyLengthLimitTest extends TestCase
{
    public function test_the_limit_is_livewires_own_payload_cap(): void
    {
        require_once base_path('bootstrap/body-length-cap.php');

        $this->assertSame(config('livewire.payload.max_size'), OPENPNE_MAX_BODY_BYTES);
    }
}
