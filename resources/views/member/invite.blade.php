@extends('layouts.classic')

@section('title', __('Invite a new member'))

@section('content')
    <x-classic.parts id="inviteForm" name="form" :title="__('Invite a new member')">
        <form method="POST" action="{{ route('member.invite.submit') }}">
            @csrf
            <div class="block">{{ __('Enter an email address to send a registration link.') }}</div>
            <dl>
                <dt><label for="email">{{ __('Email') }}</label></dt>
                <dd>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required>
                    @error('email')<p role="alert">{{ $message }}</p>@enderror
                </dd>
                <dt><label for="message">{{ __('Message (optional)') }}</label></dt>
                <dd>
                    <textarea id="message" name="message" rows="4">{{ old('message') }}</textarea>
                    @error('message')<p role="alert">{{ $message }}</p>@enderror
                </dd>
            </dl>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Send invitation') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
