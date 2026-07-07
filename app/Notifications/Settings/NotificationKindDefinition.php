<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

/**
 * One NotificationKind's registry entry (see NotificationKind::definition()). Untranslated:
 * caption is a source string; __() is applied where it is displayed.
 */
final readonly class NotificationKindDefinition
{
    public function __construct(
        public NotificationCategory $category,
        /**
         * The item name in the OpenPNE 3 extension's notification_config.yml. The member_config
         * keys derive from it exactly as the extension stored them: web = "is_send_{name}_web",
         * mail = "is_send_pc_{name}_mail".
         */
        public string $op3Name,
        /** Member-facing toggle label (untranslated source string; %term% placeholders apply). */
        public string $caption,
        /**
         * The "(x only)" variant relation (the extension's `dependOnNot`): this kind only takes
         * effect while $dependOnNot is disabled for the recipient — an enabled $dependOnNot
         * already covers the narrower audience.
         */
        public ?NotificationKind $dependOnNot = null,
        /**
         * Whether OpenPNE 4 has a sender for this kind. Unwired kinds exist for import fidelity
         * (the one-shot upgrade preserves the member's OpenPNE 3 choice) but are hidden from the
         * settings UI until their sender lands.
         */
        public bool $isWired = false,
    ) {}
}
