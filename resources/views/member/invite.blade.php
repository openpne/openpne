@extends('layouts.classic')

@section('title', __('Invite a new member'))

@section('content')
    <x-classic.parts id="inviteForm" name="form" :title="__('Invite a new member')">
        <form method="POST" action="{{ route('member.invite.submit') }}">
            @csrf
            <table>
                <tr>
                    <th><label for="member_config_mail_address">{{ __('Email') }}</label></th>
                    <td>
                        <input type="email" class="input_text" id="member_config_mail_address" name="email" value="{{ old('email') }}" required>
                        @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="member_config_message">{{ __('Message (optional)') }}</label></th>
                    <td>
                        <textarea id="member_config_message" name="message" rows="4">{{ old('message') }}</textarea>
                        @error('message')<p class="error" role="alert">{{ $message }}</p>@enderror
                    </td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Send invitation') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
