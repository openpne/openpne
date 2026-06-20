@include('partials.login-form', [
    'registrationOpen' => $registrationOpen,
    'captchaRequired' => $captchaRequired,
    'challengeUrl' => $challengeUrl,
])
