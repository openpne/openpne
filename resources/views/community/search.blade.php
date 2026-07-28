@extends('layouts.classic')

@section('title', __('Search %communities%'))

@section('content')
    <x-classic.parts id="searchCommunity" name="form" :title="__('Search %communities%')">
        <form method="GET" action="{{ route('community.search') }}">
            <table>
                <tr>
                    <th><label for="search_name">{{ __('Keyword') }}</label></th>
                    <td><input type="text" class="input_text" id="search_name" name="community[name]" value="{{ $keyword }}"></td>
                </tr>
                <tr>
                    <th><label for="search_category">{{ __('%Community% Category') }}</label></th>
                    <td>
                        <select id="search_category" name="community[community_category_id]">
                            <option value="">{{ __('All categories') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->getKey() }}" @selected($categoryId === $category->getKey())>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
            </table>

            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Search') }}"></li>
                </ul>
            </div>
        </form>

        {{-- searchSuccess.php passes the create link as the form kind's moreInfo option, which the
             OpenPNE 3 parts frame renders after the form as div.moreInfo > ul.moreInfo. --}}
        <div class="moreInfo">
            <ul class="moreInfo">
                <li><a href="{{ route('community.edit') }}">{{ __('Create a new %community%') }}</a></li>
            </ul>
        </div>
    </x-classic.parts>

    @if ($communities->isEmpty())
        {{-- OpenPNE 3 searchSuccess.php swaps the result list for a plain box once the pager is
             empty, keeping the same id. --}}
        <x-classic.parts id="searchCommunityResult" name="box" :title="__('Search Results')">
            <div class="body">{{ __('No %communities% found.') }}</div>
        </x-classic.parts>
    @else
        @php
            $results = $communities->map(fn ($community) => [
                'url' => route('community.show', $community),
                'file' => $community->image,
                'name' => $community->name,
                'rows' => [
                    ['caption' => __('%Community% Name'), 'value' => $community->name],
                    ['caption' => __('Count of Members'), 'value' => (string) $community->members_count],
                    ['caption' => __('Description'), 'value' => (string) $community->description],
                ],
            ])->all();
        @endphp
        <x-classic.parts id="searchCommunityResult" name="searchResultList" :title="__('Search Results')">
            <x-classic.search-result-list :items="$results" :paginator="$communities->withQueryString()" />
        </x-classic.parts>
    @endif
@endsection
