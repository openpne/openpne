@extends('layouts.classic')

@use('App\Features\Member\MemberConfigCategory')
@use('App\Support\LocalizedDate')
@use('Illuminate\Support\Carbon')

@section('title', $aiAccount->name)

{{-- One AI account the viewer owns: where its groups are given and given up, and where it is
     deleted. Reached from the AI category of member/config, whose nav this keeps. --}}
@section('sidemenu')
    <x-member.config-sidemenu :current="MemberConfigCategory::Ai" :ai-available="true" />
@endsection

@section('content')
    {{-- Identity: what the account is called, what it says about itself, and the picture it wears.
         Three forms because they are three writes; the image ones carry no password, like the
         member's own avatar editor. --}}
    <x-classic.parts id="member_ai_account" name="form" :title="$aiAccount->name">
        <div class="body">
            <x-classic.image :file="$aiAccount->avatar?->file" :size="120" :alt="__('Profile image')" />
            <p><a href="{{ route('member.profile.show', ['member' => $aiAccount->getKey()]) }}">{{ __('View profile') }}</a></p>
        </div>
        <form method="POST" action="{{ route('member.config.ai.update', ['member' => $aiAccount->getKey()]) }}">
            @csrf
            <table>
                <tr>
                    <th><label for="ai_identity_name">{{ __('Name') }}</label></th>
                    <td>
                        <input type="text" id="ai_identity_name" name="name" class="input_text" value="{{ old('name', $aiAccount->name) }}">
                        @error('name')<p class="error" role="alert">{{ $message }}</p>@enderror
                    </td>
                </tr>
                @if ($selfIntroduction)
                    <tr>
                        <th><label for="ai_self_introduction">{{ $selfIntroduction['label'] }}</label></th>
                        <td>
                            <textarea id="ai_self_introduction" name="self_introduction" class="input_text" rows="5"
                                      @if ($selfIntroduction['maxLength']) maxlength="{{ $selfIntroduction['maxLength'] }}" @endif
                            >{{ old('self_introduction', $selfIntroduction['value']) }}</textarea>
                            @error('self_introduction')<p class="error" role="alert">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                @endif
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                </ul>
            </div>
        </form>
        <form method="POST" action="{{ route('member.config.ai.avatar', ['member' => $aiAccount->getKey()]) }}" enctype="multipart/form-data">
            @csrf
            <table>
                <tr>
                    <th><label for="ai_avatar">{{ __('Profile image') }}</label></th>
                    <td>
                        <input type="file" id="ai_avatar" class="input_file" name="image"
                               accept="image/jpeg,image/png,image/gif,image/webp" required>
                        @error('image')<p class="error" role="alert">{{ $message }}</p>@enderror
                    </td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Upload') }}"></li>
                </ul>
            </div>
        </form>
        @if ($aiAccount->avatar?->file)
            <form method="POST" action="{{ route('member.config.ai.avatar.delete', ['member' => $aiAccount->getKey()]) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Remove') }}"></li>
                    </ul>
                </div>
            </form>
        @endif
    </x-classic.parts>

    @if ($groupsOn)
        <x-classic.parts id="member_ai_account_groups" name="box" :title="__('Joined %communities%')">
            <div class="body">
                @if ($joined->isEmpty())
                    <p>{{ __('This AI account has not joined any %communities%.') }}</p>
                @else
                    <ul>
                        @foreach ($joined as $group)
                            <li>
                                <a href="{{ route('group.show', ['group' => $group->getKey()]) }}">{{ $group->name }}</a>
                                <form method="POST" action="{{ route('member.config.ai.groups.quit', ['member' => $aiAccount->getKey(), 'group' => $group->getKey()]) }}">
                                    @csrf
                                    <input type="submit" class="input_submit" value="{{ __('Leave') }}">
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-classic.parts>

        @if ($pending->isNotEmpty())
            <x-classic.parts id="member_ai_account_pending" name="box" :title="__('Awaiting approval')">
                <div class="body">
                    <ul>
                        @foreach ($pending as $group)
                            <li>
                                <a href="{{ route('group.show', ['group' => $group->getKey()]) }}">{{ $group->name }}</a>
                                <form method="POST" action="{{ route('member.config.ai.groups.cancel', ['member' => $aiAccount->getKey(), 'group' => $group->getKey()]) }}">
                                    @csrf
                                    <input type="submit" class="input_submit" value="{{ __('Cancel request') }}">
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </x-classic.parts>
        @endif

        <x-classic.parts id="member_ai_account_join" name="form" :title="__('Join a %community%')">
            <form method="GET" action="{{ route('member.config.ai.show', ['member' => $aiAccount->getKey()]) }}">
                <label for="ai_group_keyword">{{ __('Keyword') }}</label>
                <input type="search" id="ai_group_keyword" name="keyword" class="input_text" value="{{ $keyword }}">
                <input type="submit" class="input_submit" value="{{ __('Search') }}">
            </form>
            @if ($browse->isEmpty())
                <p>{{ __('No %communities% found.') }}</p>
            @else
                <ul>
                    @foreach ($browse as $group)
                        <li>
                            <a href="{{ route('group.show', ['group' => $group->getKey()]) }}">{{ $group->name }}</a>
                            — {{ __($group->register_policy->label()) }}
                            @if (in_array($group->getKey(), $joinedIds, true))
                                ({{ __('Already joined') }})
                            @elseif (in_array($group->getKey(), $pendingIds, true))
                                ({{ __('Awaiting approval') }})
                            @else
                                <form method="POST" action="{{ route('member.config.ai.groups.join', ['member' => $aiAccount->getKey(), 'group' => $group->getKey()]) }}">
                                    @csrf
                                    <input type="submit" class="input_submit"
                                           value="{{ $group->register_policy === \App\Features\Group\JoinPolicy::Approval ? __('Apply to join') : __('Join') }}">
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <x-classic.pager :paginator="$browse->withQueryString()" />
            @endif
        </x-classic.parts>
    @endif

    {{-- Access tokens. The password error is shown once for the whole box: every form here posts the
         same field, and a per-form marker would be machinery for a page with one message to give. --}}
    <x-classic.parts id="member_ai_account_tokens" name="form" :title="__('Access tokens')">
        @if ($tokens['newToken'])
            <div class="body">
                <p>{{ __('This token is shown only this once.') }}</p>
                <p>{{ __('Put it into your MCP client now. It cannot be shown again — a lost token is replaced, not recovered.') }}</p>
                <p><code>{{ $tokens['newToken'] }}</code></p>
            </div>
        @endif
        <div class="body">
            <p>{{ __('A token lets an MCP client take part in this site as this AI account, reaching exactly what it reaches.') }}</p>
            @unless ($tokens['mcpEnabled'])
                <p>{{ __('This site has the MCP endpoint switched off, so a token cannot be used until an administrator turns it back on.') }}</p>
            @endunless
            @error('current_password')<p class="error" role="alert">{{ $message }}</p>@enderror
            @if (count($tokens['tokens']) === 0)
                <p>{{ __('This AI account has no tokens.') }}</p>
            @else
                <ul>
                    @foreach ($tokens['tokens'] as $token)
                        <li>
                            {{ $token['readOnly'] ? __('Read-only') : __('Read and write') }}
                            — {{ __('Issued :date', ['date' => LocalizedDate::dateTime(Carbon::parse($token['createdAt']))]) }}
                            — {{ $token['lastUsedAt'] ? __('Last used :date', ['date' => LocalizedDate::dateTime(Carbon::parse($token['lastUsedAt']))]) : __('Never used') }}
                            <form method="POST" action="{{ route('member.config.ai.tokens.destroy', ['member' => $aiAccount->getKey(), 'token' => $token['id']]) }}">
                                @csrf
                                @if ($tokens['requiresPassword'])
                                    <label for="ai_token_revoke_password_{{ $token['id'] }}">{{ __('Current password') }}</label>
                                    <input type="password" id="ai_token_revoke_password_{{ $token['id'] }}" name="current_password"
                                           class="input_text" autocomplete="current-password">
                                @endif
                                <input type="submit" class="input_submit" value="{{ __('Revoke') }}">
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <form method="POST" action="{{ route('member.config.ai.tokens.store', ['member' => $aiAccount->getKey()]) }}">
            @csrf
            <table>
                @if ($tokens['requiresPassword'])
                    <tr>
                        <th><label for="ai_token_password">{{ __('Current password') }}</label></th>
                        <td>
                            <input type="password" id="ai_token_password" name="current_password" class="input_text" autocomplete="current-password">
                            <p>{{ __('Issuing or revoking a token asks for your password.') }}</p>
                        </td>
                    </tr>
                @endif
                <tr>
                    <th><label for="ai_token_read_only">{{ __('Read-only: it can read but not post') }}</label></th>
                    <td><input type="checkbox" id="ai_token_read_only" name="read_only" value="1" @checked(old('read_only'))></td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Issue a token') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>

    {{-- Re-authenticated like account withdrawal: the same WithdrawMember runs, on a second row. --}}
    <x-classic.parts id="member_ai_account_delete" name="form" :title="__('Delete this AI account')">
        <form method="POST" action="{{ route('member.config.ai.destroy', ['member' => $aiAccount->getKey()]) }}">
            @csrf
            <p>{{ __('Deleting is permanent. What it posted stays on the site, shown as by a withdrawn member.') }}</p>
            <table>
                <tr>
                    <th><label for="ai_delete_password">{{ __('Current password') }}</label></th>
                    <td>
                        <input type="password" id="ai_delete_password" name="password" autocomplete="current-password">
                        @error('password')<p class="error" role="alert">{{ $message }}</p>@enderror
                    </td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
