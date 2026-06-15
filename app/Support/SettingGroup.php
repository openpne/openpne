<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which admin page a SnsSettingKey belongs to. Pages render only their own group
 * (SnsSettingKey::inGroup), so adding an Auth-group key never leaks it into the
 * identity "base settings" page.
 */
enum SettingGroup
{
    /** Identity / display settings edited on the "SNS base settings" page. */
    case Base;

    /** Registration / authentication settings (added with the auth settings page). */
    case Auth;

    /** Per-context gadget layout choice, edited on the gadget layout page (not the base page). */
    case GadgetLayout;
}
