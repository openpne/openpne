@extends('layouts.classic')

@php($title = $group ? __('Edit %community%') : __('Create a %community%'))

@section('title', $title)

@section('content')
    {{-- OpenPNE 3 editSuccess.php serves both create and edit from one formCommunity box. --}}
    <x-classic.parts id="formCommunity" name="form" :title="$title">
        @if ($errors->any())
            <ul class="errorList">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('group.save', $group ? ['id' => $group->getKey()] : []) }}" enctype="multipart/form-data">
            @csrf
            <table class="formTable">
                <tr>
                    <th>{{ __('Name') }}</th>
                    <td><input type="text" name="name" value="{{ old('name', $group?->name) }}" maxlength="64" required></td>
                </tr>
                <tr>
                    <th>{{ __('Description') }}</th>
                    <td><textarea name="description">{{ old('description', $group?->description) }}</textarea></td>
                </tr>
                <tr>
                    <th>{{ __('Join policy') }}</th>
                    <td>
                        <ul class="radio_list">
                            @foreach ($policies as $policy)
                                <li><input type="radio" name="register_policy" value="{{ $policy['slug'] }}" id="community_config_register_policy_{{ $policy['slug'] }}" class="input_radio" @checked(old('register_policy', $group?->register_policy?->slug() ?? $policies[0]['slug']) === $policy['slug'])> <label for="community_config_register_policy_{{ $policy['slug'] }}">{{ __($policy['label']) }}</label></li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
                {{-- OpenPNE 3's two opCommunityTopicPlugin settings (community_config.yml
                     public_flag / topic_authority), radios as its form drew them. The wording is
                     the enums' — the same captions the community home prints. --}}
                <tr>
                    <th>{{ __('Authority to Read %Topic%') }}</th>
                    <td>
                        <ul class="radio_list">
                            @foreach ($topicReadChoices as $choice)
                                <li><input type="radio" name="topic_read_access" value="{{ $choice['slug'] }}" id="community_config_public_flag_{{ $choice['slug'] }}" class="input_radio" @checked(old('topic_read_access', $group?->topic_read_access->slug() ?? 'everyone') === $choice['slug'])> <label for="community_config_public_flag_{{ $choice['slug'] }}">{{ __($choice['label']) }}</label></li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Authority to Create %Topic%') }}</th>
                    <td>
                        <ul class="radio_list">
                            @foreach ($topicPostChoices as $choice)
                                <li><input type="radio" name="topic_post_authority" value="{{ $choice['slug'] }}" id="community_config_topic_authority_{{ $choice['slug'] }}" class="input_radio" @checked(old('topic_post_authority', $group?->topic_post_authority->slug() ?? 'members') === $choice['slug'])> <label for="community_config_topic_authority_{{ $choice['slug'] }}">{{ __($choice['label']) }}</label></li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Receive a notice mail when member joined') }}</th>
                    <td>
                        {{-- OpenPNE 3 CommunityConfigForm's two-option radio (Receive / Don't Receive) with
                             its help line; a radio is always submitted, so an unchecked state survives a
                             validation round-trip on its own. --}}
                        <ul class="radio_list">
                            @foreach ([1 => __('Receive'), 0 => __("Don't Receive")] as $value => $label)
                                <li><input type="radio" name="is_join_notification_enabled" value="{{ $value }}" id="community_config_is_join_notification_enabled_{{ $value }}" class="input_radio" @checked((int) old('is_join_notification_enabled', (int) ($group?->is_join_notification_enabled ?? true)) === $value)> <label for="community_config_is_join_notification_enabled_{{ $value }}">{{ $label }}</label></li>
                            @endforeach
                        </ul>
                        <div class="help">{{ __('Send a notice mail to administrator when new member joined the %community%.') }}</div>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Category') }}</th>
                    <td>
                        <select name="group_category_id">
                            <option value="">{{ __('No category') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->getKey() }}" @selected((int) old('group_category_id', $group?->group_category_id) === $category->getKey())>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Image') }}</th>
                    <td>
                        @if ($group?->image)
                            <p class="photo">
                                <img src="{{ $group->image->thumbnailUrl(120, 120, square: true) }}" alt="">
                                <label><input type="checkbox" name="remove_image" value="1"> {{ __('Delete') }}</label>
                            </p>
                        @endif
                        <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
                        @error('image')<p class="error">{{ $message }}</p>@enderror
                    </td>
                </tr>
            </table>

            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                    @if ($group)
                        <li><a href="{{ route('group.show', $group) }}">{{ __('Cancel') }}</a></li>
                    @endif
                </ul>
            </div>
        </form>
    </x-classic.parts>

    @if ($canDelete)
        {{-- OpenPNE 3 editSuccess.php's buttonBox kind, shown to the administrator only (a sub-admin
             may edit but not delete). The kind puts its form inside the operation li. --}}
        <x-classic.parts id="deleteForm" name="buttonBox" :title="__('Delete this %community%')">
            <div class="block">{{ __('This deletes the %community%. Tell its members in advance when you do.') }}</div>
            <div class="operation">
                <ul class="moreInfo button">
                    <li>
                        <form method="GET" action="{{ route('group.delete.show', $group) }}">
                            <input type="submit" class="input_submit" value="{{ __('Delete') }}">
                        </form>
                    </li>
                </ul>
            </div>
        </x-classic.parts>
    @endif
@endsection
