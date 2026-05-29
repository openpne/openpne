@extends('layouts.classic')

@section('title', __('Blocked members'))

@section('content')
    <div class="dparts" id="block_add">
        <h2 class="partsHeading">{{ __('Block a member') }}</h2>
        <div class="parts">
            <form method="GET" action="{{ route('block.add.show') }}">
                <label for="block_member_id">{{ __('Member ID') }}</label>
                <input type="number" name="id" id="block_member_id" min="1" required>
                <button type="submit">{{ __('Block') }}</button>
            </form>
            <p class="help">{{ __('The member ID is the number at the end of the member page URL.') }}</p>
        </div>
    </div>

    <div class="dparts" id="block_list">
        <h2 class="partsHeading">{{ __('Blocked members') }}</h2>
        <div class="parts">
            @if ($blocks->isEmpty())
                <p>{{ __('No blocked members.') }}</p>
            @else
                <ul class="blockList">
                    @foreach ($blocks as $blocked)
                        <li>
                            <span class="memberName">{{ $blocked->name }}</span>
                            <a href="{{ route('block.remove.show', $blocked) }}">{{ __('Unblock') }}</a>
                        </li>
                    @endforeach
                </ul>

                {{ $blocks->links() }}
            @endif
        </div>
    </div>
@endsection
