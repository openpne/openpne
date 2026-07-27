@extends('layouts.classic')

@section('title', __('Register'))

@section('content')
    {{-- Neutral confirmation, shown whether or not the address is already a member
         (enumeration-safe). --}}
    <x-classic.parts id="requestSuccess" name="box" :title="__('Register')">
        <div class="body">
            <p>{{ __('We sent you a registration link.') }}</p>
            <p>{{ __('Open the link in the mail to begin your registration.') }}</p>
            {{-- Enumeration-safe hint: the screen is identical for a brand-new and an already-registered
                 address, but a registered one is silently not mailed, so point that user somewhere useful. --}}
            <p>{{ __('If no email arrives, this address may already be registered. Try signing in, or reset your password.') }}</p>
            <p class="loginLink"><a href="{{ route('login') }}">{{ __('Back to login') }}</a></p>
        </div>
    </x-classic.parts>
@endsection
