@extends('layouts.classic')

@section('title', __('Join this %community%'))

@section('content')
    {{-- OpenPNE 3 joinInput.php's form kind: the question is the form's `body`, rendered as a
         .block inside the form tag. --}}
    <x-classic.parts id="communityJoining" name="form" :title="__('Join this %community%')">
        <form method="POST" action="{{ route('group.join', $group) }}">
            @csrf
            <input type="hidden" name="id" value="{{ $group->getKey() }}">
            <div class="block">
                @if ($group->register_policy === \App\Features\Group\JoinPolicy::Approval)
                    {{ __('This %community% requires admin approval. Send a join request to :name?', ['name' => $group->name]) }}
                @else
                    {{ __('Join :name?', ['name' => $group->name]) }}
                @endif
            </div>
            {{-- joinInput.php's firstRow: the form kind's table carries no fields here, only the
                 preview of what is being joined. --}}
            <table>
                <tr>
                    <th>{{ __('Photo') }}</th>
                    <td><a href="{{ route('group.show', $group) }}"><x-classic.image :file="$group->image" :size="76" :alt="$group->name" /></a> </td>
                </tr>
                <tr>
                    <th>{{ __('%Community%') }}</th>
                    <td><a href="{{ route('group.show', $group) }}">{{ $group->name }}</a></td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Join') }}"></li>
                    <li><a href="{{ route('group.show', $group) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
