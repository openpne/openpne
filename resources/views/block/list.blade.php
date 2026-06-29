@extends('layouts.classic')

@section('title', __('block.blocked_members'))

@section('content')
    <div class="dparts" id="block_add">
        <div class="partsHeading"><h3>{{ __('block.add_member') }}</h3></div>
        <div class="parts">
            <form method="GET" action="{{ route('block.add.show') }}">
                <label for="block_member_id">{{ __('block.member_id') }}</label>
                <input type="number" class="input_text" name="id" id="block_member_id" min="1" required>
                <input type="submit" class="input_submit" value="{{ __('block.block') }}">
            </form>
            <p class="help">{{ __('block.member_id_hint') }}</p>
        </div>
    </div>

    <div class="dparts" id="block_list">
        <div class="partsHeading"><h3>{{ __('block.blocked_members') }}</h3></div>
        <div class="parts">
            @if ($blocks->isEmpty())
                <p>{{ __('block.none') }}</p>
            @else
                <ul class="blockList">
                    @foreach ($blocks as $blocked)
                        <li>
                            <span class="memberName">{{ $blocked->name }}</span>
                            <a href="{{ route('block.remove.show', $blocked) }}">{{ __('block.unblock') }}</a>
                        </li>
                    @endforeach
                </ul>

                {{ $blocks->links() }}
            @endif
        </div>
    </div>
@endsection
