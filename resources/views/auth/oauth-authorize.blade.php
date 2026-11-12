@extends('layouts.classic')

@section('title', __('Connect :host', ['host' => $client['host']]))

@section('content')
    {{-- Same field names as the Modern screen (resources/js/pages/auth/oauth-authorize.tsx): both post
         the plain form App\Mcp\OAuth\ApproveController reads; no script, so the actor is one radio group. --}}
    <x-classic.parts id="oauthAuthorize" name="form" :title="__('Connect :host', ['host' => $client['host']])">
        @php($offersAi = $actors['aiAccounts'] !== [] || $actors['canCreate'])
        @php($selected = old('actor', $actors['aiAccounts'] !== [] ? 'ai:'.$actors['aiAccounts'][0]['id'] : ($actors['canCreate'] ? 'new' : 'self')))
        <p>{{ __('“:name” is asking to connect.', ['name' => $client['name']]) }}</p>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <table>
                @if ($offersAi)
                    <tr>
                        <th>{{ __('Who takes part?') }}</th>
                        <td>
                            <p>{{ __('As an AI account') }} — {{ __('A separate account marked as AI takes part. Posts appear under its name.') }}</p>
                            @foreach ($actors['aiAccounts'] as $account)
                                <p><label><input type="radio" name="actor" value="ai:{{ $account['id'] }}" @checked($selected === 'ai:'.$account['id'])> {{ $account['name'] }}</label></p>
                            @endforeach
                            @if ($actors['canCreate'])
                                <p><label><input type="radio" name="actor" value="new" @checked($selected === 'new')> {{ __('Create a new one') }}</label>
                                    <label for="oauth_new_name">{{ __('Name') }}</label>
                                    <input type="text" id="oauth_new_name" name="name" class="input_text" maxlength="255" value="{{ old('name') }}"></p>
                                @error('name')<p class="error" role="alert">{{ $message }}</p>@enderror
                            @endif
                            <p><label><input type="radio" name="actor" value="self" @checked($selected === 'self')> {{ __('As yourself') }}</label> — {{ __('Reads and writes under your name. What the AI writes is yours.') }}</p>
                            @error('actor')<p class="error" role="alert">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                @else
                    <tr>
                        <td colspan="2">
                            <input type="hidden" name="actor" value="self">
                            {{ __('Reads and writes under your name. What the AI writes is yours.') }}
                        </td>
                    </tr>
                @endif
                <tr>
                    <th>{{ __('Permissions') }}</th>
                    <td><label><input type="checkbox" name="read_only" value="1" @checked(old('read_only'))> {{ __('Read-only: it can read but not post') }}</label></td>
                </tr>
                @if ($requiresPassword)
                    @include('member.partials.reauth-fields', ['part' => 'rows', 'idPrefix' => 'consent', 'passwordHelp' => __('Connecting asks for your password.')])
                @endif
            </table>
            @if ($requiresPassword)
                @include('member.partials.reauth-fields', ['part' => 'recovery', 'idPrefix' => 'consent'])
            @endif
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Connect') }}"></li>
                </ul>
            </div>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Cancel') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
