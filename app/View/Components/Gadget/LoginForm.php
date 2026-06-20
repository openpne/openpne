<?php

namespace App\View\Components\Gadget;

use App\Features\Auth\LoginFormData;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * OpenPNE 3 default/loginFormBox: the login form as a gadget. Reuses the shared login-form partial
 * (CAPTCHA / registration / error behaviour) and re-resolves its render state from the request, so
 * the form is identical to the fixed login page.
 */
class LoginForm extends Component
{
    public bool $registrationOpen;

    public bool $captchaRequired;

    public string $challengeUrl;

    /** @param array<string, mixed> $config */
    public function __construct(
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        $data = LoginFormData::for(request());
        $this->registrationOpen = $data['registrationOpen'];
        $this->captchaRequired = $data['captchaRequired'];
        $this->challengeUrl = $data['challengeUrl'];
    }

    public function render(): View
    {
        return view('components.gadget.login-form');
    }
}
