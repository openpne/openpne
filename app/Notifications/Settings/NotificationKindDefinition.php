<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

use App\Support\Feature;

/** Caption is an untranslated source string; __() is applied where it is displayed. */
final readonly class NotificationKindDefinition
{
    public function __construct(
        public NotificationCategory $category,
        public string $caption,
        /**
         * The item name in the OpenPNE 3 extension's notification_config.yml, or null for a native kind
         * the extension never stored.
         */
        public ?string $op3Name = null,
        /**
         * The "(x only)" variant relation: this kind only takes effect while $dependOnNot is
         * disabled for the recipient — an enabled $dependOnNot already covers the narrower
         * audience.
         */
        public ?NotificationKind $dependOnNot = null,
        /**
         * Whether OpenPNE 4 has a sender; an unwired kind exists for import fidelity alone and is hidden
         * from the settings UI.
         */
        public bool $isWired = false,
        /**
         * A second unit this kind needs beyond its category's (a friends-only variant needs friends):
         * the settings stop offering it, but each fan-out must check isAvailable() itself to stop delivery.
         */
        public ?Feature $dependsOn = null,
    ) {}
}
