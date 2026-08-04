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

    /** How the install serves the Classic/Modern surfaces (App\Support\SurfaceMode); no admin page yet — set at install/upgrade and via the openpne:surface-mode command. */
    case Surface;

    /** Registration / authentication settings (added with the auth settings page). */
    case Auth;

    /** Per-context gadget layout choice, edited on the gadget layout page (not the base page). */
    case GadgetLayout;

    /** OpenPNE 3 design customizations (custom CSS, PC HTML insertion slots, footer HTML), edited on the design page. */
    case Design;

    /** Member-privacy policy settings (e.g. whether members may make their age web-public), edited on the "member privacy" page. */
    case Privacy;

    /** Timeline policy settings (e.g. whether members may make posts web-public), edited on the "timeline settings" page. */
    case Timeline;

    /** Diary policy settings (e.g. whether members may make entries web-public), edited on the "diary settings" page. */
    case Diary;

    /** Per-feature availability toggles (App\Support\Feature), edited on the features page. */
    case Features;

    /** Per-site branding (brand color, logo mark, favicon), edited on the branding page. */
    case Branding;

    /** The administrator-authored message shown on the login screen, edited on the login screen page. */
    case LoginScreen;
}
