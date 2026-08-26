<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

/**
 * OpenPNE 3's `default` module: the two site policy screens, served in place at /terms and /privacy
 * with every OpenPNE 3 URL for them preserved by redirect (compatRedirects()); the customizing
 * stylesheet, served at its OpenPNE 3 URL; and the module's helper and error catch-alls, which are
 * gaps. The native maps come first so a screens() key resolves to the page, not to a redirect.
 */
class PolicyRouteParity extends RouteParity
{
    protected string $module = 'policy';

    public function openpne3Module(): ?string
    {
        return 'default';
    }

    public function maps(): array
    {
        return [
            new RouteMap(null, null, 'policy.terms', 'GET', op3Action: 'userAgreement', op3Module: 'default'),
            new RouteMap(null, null, 'policy.privacy', 'GET', op3Action: 'privacyPolicy', op3Module: 'default'),
            // The OpenPNE 3 URLs answer with a permanent redirect to the pair above.
            new RouteMap('global_user_agreement', '/userAgreement', 'policy.terms_compat', 'GET', op3Action: 'userAgreement', op3Module: 'default'),
            new RouteMap('user_agreement', '/default/userAgreement', 'policy.terms.default_compat', 'GET', op3Action: 'userAgreement', op3Module: 'default'),
            new RouteMap('global_privacy_policy', '/privacyPolicy', 'policy.privacy_compat', 'GET', op3Action: 'privacyPolicy', op3Module: 'default'),
            new RouteMap('privacy_policy', '/default/privacyPolicy', 'policy.privacy.default_compat', 'GET', op3Action: 'privacyPolicy', op3Module: 'default'),
            new RouteMap('customizing_css', '/cache/css/customizing.:sf_format', 'design.customizing_css', 'GET', op3Action: 'customizingCss', op3Module: 'default'),
        ];
    }

    public function gaps(): array
    {
        return [
            'global_search' => 'Not ported: /search?search_module=X only forwarded to X/search, and each search keeps its own URL.',
            'url_for' => 'Not ported: urlFor.txt resolved route names for OpenPNE 3\'s own scripts, which are not ported.',
            'error' => 'Not ported: an error catch-all; the fallback route answers 404 in the Classic error shell.',
            'no_default' => 'Not ported: an error catch-all; the fallback route answers 404 in the Classic error shell.',
            'no_symfony' => 'Not ported: an error catch-all; the fallback route answers 404 in the Classic error shell.',
            'member_profile_no_default' => 'Not ported: an error catch-all; /member/profile/* answers 404 in the Classic error shell.',
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
            // customizingCssAction: no page, the stored stylesheet as text/css.
            'customizingCss' => [
                new ScreenElement('stylesheet body', L::One, S::Ported, "customizingCssAction \$op_config['customizing_css'] as text/css", 'the same setting (SnsSettingKey::CustomCss) served at the OpenPNE 3 URL'),
            ],
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
