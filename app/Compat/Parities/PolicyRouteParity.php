<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

/**
 * The two site policy screens OpenPNE 3 served from its `default` module, whose OpenPNE 3 URLs are
 * preserved by compatRedirects() rather than served in place: the canonical pair moved to /terms and
 * /privacy.
 *
 * openpne3Module() is null for a reason that is a debt, not a fact: OpenPNE 3 did name these routes
 * (global_user_agreement / global_privacy_policy), but the route inventory carries no `default`
 * module, and adding one means accounting for every other route in it (global_search, url_for,
 * no_symfony…). Until it does, these are native maps — they derive the body id from
 * op3Module/op3Action without binding to an inventory entry — and the redirects are held by
 * Tests\Feature\Policy\PolicyPageTest instead of the inventory URL audit.
 */
class PolicyRouteParity extends RouteParity
{
    protected string $module = 'policy';

    public function openpne3Module(): ?string
    {
        return null;
    }

    public function maps(): array
    {
        return [
            new RouteMap(null, null, 'policy.terms', 'GET', op3Action: 'userAgreement', op3Module: 'default'),
            new RouteMap(null, null, 'policy.privacy', 'GET', op3Action: 'privacyPolicy', op3Module: 'default'),
        ];
    }

    public function compatRedirects(): array
    {
        // Both the global routes and their /default/ twins; OpenPNE 3 answered all four.
        return [
            '/userAgreement' => 'policy.terms',
            '/default/userAgreement' => 'policy.terms',
            '/privacyPolicy' => 'policy.privacy',
            '/default/privacyPolicy' => 'policy.privacy',
        ];
    }

    public function screens(): array
    {
        return [
            // userAgreementSuccess.php / privacyPolicySuccess.php: one box, no heading, body only.
            'userAgreement' => $this->policyScreen('user_agreement', 'userAgreement'),
            'privacyPolicy' => $this->policyScreen('privacy_policy', 'privacyPolicy'),
        ];
    }

    /** @return list<ScreenElement> */
    private function policyScreen(string $setting, string $boxId): array
    {
        return [
            new ScreenElement('policy body', L::One, S::Partial, "op_include_box('{$boxId}', nl2br(\$op_config['{$setting}']))",
                'OpenPNE 3 emitted the stored value as raw HTML; OpenPNE 4 renders it as Markdown through the body sanitizer, and the upgrade rewrites the OpenPNE 3 text (App\Upgrade\Runner\Op3PolicyMarkdown)'),
            new ScreenElement('box id', L::Two, S::Ported, "op_include_box('{$boxId}', …)"),
            new ScreenElement('heading', L::Three, S::Ported, 'none', 'OpenPNE 4 addition: the OpenPNE 3 screen had no heading at all'),
            new ScreenElement('footer link', L::Two, S::Ported, "_footer.php link_to('@{$setting}')"),
        ];
    }
}
