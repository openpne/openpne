@extends('layouts.classic')

@section('title', __('Blocked members'))

@section('content')
    <div class="dparts" id="block_add">
        <div class="partsHeading"><h3>{{ __('Block a member') }}</h3></div>
        <div class="parts">
            <form method="GET" action="{{ route('block.add.show') }}">
                <label for="block_member_id">{{ __('Member ID') }}</label>
                <input type="number" class="input_text" name="id" id="block_member_id" min="1" required>
                <button type="submit" class="input_submit">{{ __('Block') }}</button>
            </form>
            <p class="help">{{ __('The member ID is the number at the end of the member page URL.') }}</p>
        </div>
    </div>

    <div class="dparts" id="block_list">
        <div class="partsHeading"><h3>{{ __('Blocked members') }}</h3></div>
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
