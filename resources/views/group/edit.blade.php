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
                        <select name="register_policy">
                            @foreach ($policies as $policy)
                                <option value="{{ $policy['slug'] }}" @selected(old('register_policy', $group?->register_policy?->slug()) === $policy['slug'])>{{ __($policy['label']) }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
                {{-- OpenPNE 3's two opCommunityTopicPlugin settings (community_config.yml
                     public_flag / topic_authority), radios as its form drew them. The wording is
                     the enums' — the same captions the community home prints. --}}
                <tr>
                    <th>{{ __('Authority to Read %Topic%') }}</th>
                    <td>
                        @foreach ($topicReadChoices as $choice)
                            <label><input type="radio" name="topic_read_access" value="{{ $choice['slug'] }}" class="input_radio" @checked(old('topic_read_access', $group?->topic_read_access->slug() ?? 'everyone') === $choice['slug'])> {{ __($choice['label']) }}</label>
                        @endforeach
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Authority to Create %Topic%') }}</th>
                    <td>
                        @foreach ($topicPostChoices as $choice)
                            <label><input type="radio" name="topic_post_authority" value="{{ $choice['slug'] }}" class="input_radio" @checked(old('topic_post_authority', $group?->topic_post_authority->slug() ?? 'members') === $choice['slug'])> {{ __($choice['label']) }}</label>
                        @endforeach
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Notifications') }}</th>
                    <td>
                        {{-- hidden 0 + checkbox 1: the field is always submitted, so an unchecked box
                             survives a validation round-trip (old() sees '0', not the current value). --}}
                        <label>
                            <input type="hidden" name="is_join_notification_enabled" value="0">
                            <input type="checkbox" name="is_join_notification_enabled" value="1" @checked(old('is_join_notification_enabled', $group?->is_join_notification_enabled ?? true))>
                            {{ __('Notify admins when a member joins.') }}
                        </label>
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
