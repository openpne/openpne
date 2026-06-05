@extends('layouts.classic')

@section('title', __('Search %communities%'))

@section('content')
    <div class="dparts" id="community_search">
        <div class="partsHeading"><h3>{{ __('Search %communities%') }}</h3></div>
        <div class="parts">
            <form method="GET" action="{{ route('community.search') }}" class="searchForm">
                <input type="text" name="community[name]" value="{{ $keyword }}" placeholder="{{ __('Keyword') }}">
                <select name="community[community_category_id]">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->getKey() }}" @selected($categoryId === $category->getKey())>{{ $category->name }}</option>
                    @endforeach
                </select>
                <input type="submit" class="input_submit" value="{{ __('Search') }}">
            </form>

            @if ($communities->isEmpty())
                <p>{{ __('No %communities% found.') }}</p>
            @else
                <ul class="communityList">
                    @foreach ($communities as $community)
                        <li>
                            <a href="{{ route('community.show', $community) }}">{{ $community->name }}</a>
                        </li>
                    @endforeach
                </ul>

                {{ $communities->withQueryString()->links() }}
            @endif
        </div>
    </div>
@endsection
