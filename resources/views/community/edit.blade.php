@extends('layouts.classic')

@php($title = $community ? __('Edit %community%') : __('Create a %community%'))

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

        <form method="POST" action="{{ route('community.save', $community ? ['id' => $community->getKey()] : []) }}" enctype="multipart/form-data">
            @csrf
            <table class="formTable">
                <tr>
                    <th>{{ __('Name') }}</th>
                    <td><input type="text" name="name" value="{{ old('name', $community?->name) }}" maxlength="64" required></td>
                </tr>
                <tr>
                    <th>{{ __('Description') }}</th>
                    <td><textarea name="description">{{ old('description', $community?->description) }}</textarea></td>
                </tr>
                <tr>
                    <th>{{ __('Join policy') }}</th>
                    <td>
                        <select name="register_policy">
                            @foreach ($policies as $policy)
                                <option value="{{ $policy->value }}" @selected((int) old('register_policy', $community?->register_policy?->value) === $policy->value)>{{ __($policy->label()) }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Notifications') }}</th>
                    <td>
                        {{-- hidden 0 + checkbox 1: the field is always submitted, so an unchecked box
                             survives a validation round-trip (old() sees '0', not the current value). --}}
                        <label>
                            <input type="hidden" name="is_join_notification_enabled" value="0">
                            <input type="checkbox" name="is_join_notification_enabled" value="1" @checked(old('is_join_notification_enabled', $community?->is_join_notification_enabled ?? true))>
                            {{ __('Notify admins when a member joins.') }}
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Category') }}</th>
                    <td>
                        <select name="community_category_id">
                            <option value="">{{ __('No category') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->getKey() }}" @selected((int) old('community_category_id', $community?->community_category_id) === $category->getKey())>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>{{ __('Image') }}</th>
                    <td>
                        @if ($community?->image)
                            <p class="photo">
                                <img src="{{ $community->image->thumbnailUrl(120, 120, square: true) }}" alt="">
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
                    @if ($community)
                        <li><a href="{{ route('community.show', $community) }}">{{ __('Cancel') }}</a></li>
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
                        <form method="GET" action="{{ route('community.delete.show', $community) }}">
                            <input type="submit" class="input_submit" value="{{ __('Delete') }}">
                        </form>
                    </li>
                </ul>
            </div>
        </x-classic.parts>
    @endif
@endsection
