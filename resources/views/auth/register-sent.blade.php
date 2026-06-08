@extends('layouts.classic')

@section('title', __('Register'))

@section('content')
    {{-- OpenPNE 3 requestRegisterURLSuccess: neutral confirmation, shown whether or not the address
         is already a member (enumeration-safe). --}}
    <div class="dparts" id="requestRegisterURL">
        <div class="partsHeading"><h3>{{ __('Register') }}</h3></div>
        <div class="parts">
            <p>{{ __('We sent you a registration link.') }}</p>
            <p>{{ __('Open the link in the mail to begin your registration.') }}</p>
            <p class="loginLink"><a href="{{ route('login') }}">{{ __('Back to login') }}</a></p>
        </div>
    </div>
@endsection
