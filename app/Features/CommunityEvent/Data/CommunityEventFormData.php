<?php

namespace App\Features\CommunityEvent\Data;

/**
 * Validated event form input. Dates are carried as strings (the form sends Y-m-d) and cast on the
 * model; open_date_comment is '' when omitted (OpenPNE 3 stores empty, not null).
 */
final readonly class CommunityEventFormData
{
    public function __construct(
        public string $name,
        public string $body,
        public string $open_date,
        public string $open_date_comment,
        public string $area,
        public ?string $application_deadline,
        public ?int $capacity,
    ) {}
}
