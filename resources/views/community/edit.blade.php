@extends('layouts.classic')

@php($title = $community ? __('Edit %community%') : __('Create a %community%'))

@section('title', $title)

@section('content')
    <div class="dparts" id="community_edit">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="parts">
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
        </div>
    </div>
@endsection
